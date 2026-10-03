<?php

namespace App\Services\Research;

use App\Models\Brand;
use App\Models\Post;
use DOMDocument;
use DOMXPath;
use RuntimeException;

class DailyWebsiteContent
{
    public function __construct(private FetchSource $reader, private ContentVisual $visuals) {}

    private function dom(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);

        return new DOMXPath($document);
    }

    private function text(string $text): string
    {
        return FetchSource::normalize($text);
    }

    private function links(DOMXPath $dom, Brand $brand, string $pattern): array
    {
        $links = [];
        foreach ($dom->query('//a[@href]') as $link) {
            $url = html_entity_decode($link->getAttribute('href'), ENT_QUOTES | ENT_HTML5);
            if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
                $url = rtrim($brand->website, '/').$url;
            }
            $url = explode('#', $url, 2)[0];
            if (parse_url($url, PHP_URL_HOST) === parse_url($brand->website, PHP_URL_HOST) && preg_match($pattern, $url)) {
                $links[] = $url;
            }
        }
        $links = array_values(array_unique($links));
        usort($links, fn ($a, $b) => strcmp(hash('sha256', now()->toDateString().$a), hash('sha256', now()->toDateString().$b)));

        return $links;
    }

    public function create(Brand $brand, string $channel): ?Post
    {
        $dom = $this->dom($this->reader->publicHtml($brand->website));
        if ($brand->pyp_only) {
            $papers = [];
            foreach (array_slice($this->links($dom, $brand, '~\/course-detail\/[^?]*(?:previous-year-papers|pyp)[^?]*$~'), 0, 2) as $course) {
                $courseDom = $this->dom($this->reader->publicHtml($course));
                foreach ($courseDom->query('//section[@data-exam-type-section="previous_year"]//*[@data-exam]') as $exam) {
                    $slug = $exam->getAttribute('data-exam');
                    if (preg_match('/^[a-zA-Z0-9-]+$/', $slug)) {
                        $papers[] = rtrim($brand->website, '/').'/exam-detail/'.$slug;
                    }
                }
            }
            $papers = array_values(array_unique($papers));
            usort($papers, fn ($a, $b) => strcmp(hash('sha256', now()->toDateString().$a), hash('sha256', now()->toDateString().$b)));
            foreach (array_slice(array_unique($papers), 0, 4) as $url) {
                foreach ($this->questions($this->reader->publicHtml($url), $url) as $package) {
                    if (! $brand->posts()->where('channel', $channel)->where('visual->question', $package['visual']['question'])->exists()) {
                        return $this->save($brand, $channel, $package);
                    }
                }
            }
            throw new RuntimeException('No unused readable previous-year question was found in the public paper previews checked today.');
        }
        $states = $this->links($dom, $brand, '~\/state\/[^?]+(?:\?election=(?:pc|ac))?$~');
        if (! $states) {
            return null;
        }
        $recent = $brand->posts()->where('channel', $channel)->where('created_at', '>=', now()->subDays(30))->pluck('source_url')->all();
        $states = array_values(array_diff($states, $recent));
        foreach (array_slice($states, 0, 2) as $url) {
            $html = $this->reader->publicHtml($url);
            $candidates = [$url => $html];
            if (now()->day % 2 === 0) {
                $constituencies = $this->links($this->dom($html), $brand, '~\/constituency\?~');
                if ($constituencies) {
                    $constituency = $constituencies[0];
                    $candidates = [$constituency => $this->reader->publicHtml($constituency)] + $candidates;
                }
            }
            foreach ($candidates as $candidate => $content) {
                if ($brand->posts()->where('channel', $channel)->where('source_url', $candidate)->where('created_at', '>=', now()->subDays(30))->exists()) {
                    continue;
                }
                $package = $this->charts($content, $candidate);
                if ($package) {
                    return $this->save($brand, $channel, $package);
                }
            }
        }
        throw new RuntimeException('No unused state or constituency page with sufficient recorded chart data was found today.');
    }

    private function save(Brand $brand, string $channel, array $package): Post
    {
        $visual = $this->visuals->validate($package['visual'], $package['source_url']);
        if ($channel === 'x') {
            $package['body'] = mb_substr($package['title'], 0, 65)."\n\n".(($visual['type'] ?? '') === 'question'
                ? 'Try the sourced question in the card. Check your answer in the linked paper. #ExamPractice'
                : 'Recorded turnout, electors and votes cast in the charts. Coverage and boundaries may vary. Full page and report in the link. #ElectionData');
        }
        $post = $brand->posts()->create(['channel' => $channel] + $package);
        $post->forceFill(['visual' => $visual, 'status' => 'reviewed', 'reviewed_at' => now()])->save();

        return $post;
    }

    public function questions(string $html, string $url): array
    {
        $dom = $this->dom($html);
        $packages = [];
        foreach ($dom->query('//article[@data-pyp-question]') as $article) {
            $questionNode = $dom->query('.//*[contains(concat(" ",normalize-space(@class)," ")," pyp-preview-body ")]', $article)->item(0);
            $paperNode = $dom->query('.//*[contains(concat(" ",normalize-space(@class)," ")," pyp-preview-paper ")]', $article)->item(0);
            if (! $questionNode || ! $paperNode || $dom->query('.//img|.//svg|.//math', $article)->length || $article->getAttribute('data-multiple') === '1') {
                continue;
            }
            $question = $this->text($questionNode->textContent);
            $paper = $this->text($paperNode->textContent);
            $options = [];
            foreach ($dom->query('.//*[contains(concat(" ",normalize-space(@class)," ")," pyp-preview-option-content ")]', $article) as $option) {
                $options[] = $this->text($option->textContent);
            }
            if (! preg_match('/\b(19\d{2}|20\d{2})\b/', $paper, $year) || count($options) < 2 || count($options) > 6
                || mb_strlen($question) < 3 || mb_strlen($question) > 650 || str_contains($question.implode('', $options), '\\') || max(array_map('mb_strlen', $options)) > 150) {
                continue;
            }
            $exam = trim(str_replace($year[0], '', $paper));
            if (mb_strlen($exam) > 45) {
                continue;
            }
            $visual = ['type' => 'question', 'question' => $question, 'options' => $options, 'exam' => $exam,
                'year' => (int) $year[0], 'question_kind' => 'pyp', 'paper_url' => $url, 'provenance_verified' => true];
            $packages[] = ['title' => mb_substr($paper.' | Daily question', 0, 200), 'source_url' => $url, 'visual' => $visual,
                'body' => 'Asked in '.$paper."\n\n".$question."\n\nChoose your answer, then open the source paper to check it and practise.\n\n#PreviousYearQuestions #ExamPractice"];
        }

        return $packages;
    }

    public function charts(string $html, string $url): ?array
    {
        $dom = $this->dom($html);
        $title = $this->text($dom->query('//h1')->item(0)?->textContent ?? 'Election records');
        foreach ($dom->query('//script[@type="application/json" and contains(@class,"history-chart-data")]') as $script) {
            $data = json_decode($script->textContent, true);
            $rows = collect($data['rows'] ?? [])->filter(fn ($row) => is_array($row) && is_numeric($row['year'] ?? null))->sortBy('year')->values()->all();
            if (count($rows) < 2) {
                continue;
            }
            $note = 'Recorded data only; coverage and constituency boundaries may vary. Missing values are not estimated. Full details at source.';
            $cards = [];
            foreach (['turnout' => ['Turnout over time', '%'], 'electors' => ['Registered electors over time', 'people'], 'polled' => ['Votes cast over time', 'votes']] as $metric => [$heading, $unit]) {
                $values = array_map(fn ($row) => is_numeric($row[$metric] ?? null) ? (float) $row[$metric] : null, $rows);
                if (count(array_filter($values, fn ($v) => $v !== null)) < 2) {
                    continue;
                }
                $cards[] = ['source_url' => $url, 'visual' => ['type' => 'chart', 'chart_style' => 'line', 'heading' => mb_substr($title.' — '.$heading, 0, 100),
                    'unit' => $unit, 'note' => $note, 'labels' => array_column($rows, 'year'), 'values' => $values]];
            }
            if (! $cards) {
                continue;
            }
            $turnout = array_values(array_filter($rows, fn ($row) => is_numeric($row['turnout'] ?? null)));
            $summary = count($turnout) >= 2 ? 'Recorded turnout: '.$turnout[0]['year'].' — '.number_format((float) $turnout[0]['turnout'], 2).'% ; '.end($turnout)['year'].' — '.number_format((float) end($turnout)['turnout'], 2)."%.\n\n" : '';

            return ['title' => mb_substr($title.' | Election data report', 0, 200), 'source_url' => $url,
                'visual' => ['type' => 'collection', 'cards' => $cards],
                'body' => mb_substr($title, 0, 100)."\n\n".$summary."Explore turnout, registered electors and votes cast in the available records. Swipe through the recorded data, then open the complete page for constituency details, source notes and the report. Coverage and boundaries may vary between years.\n\n#ElectionData #Pollmedia"];
        }

        return null;
    }
}
