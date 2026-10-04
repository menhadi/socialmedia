<?php

namespace App\Services\Research;

use App\Models\Brand;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class WebsiteRotation
{
    public function __construct(private FetchSource $reader) {}

    public function links(string $html, string $website, string $pattern): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML($html, LIBXML_NONET);
        $links = [];
        foreach ((new DOMXPath($dom))->query('//a[@href]') as $anchor) {
            $url = explode('#', $anchor->getAttribute('href'), 2)[0];
            if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
                $url = rtrim($website, '/').$url;
            }
            if (parse_url($url, PHP_URL_HOST) === parse_url($website, PHP_URL_HOST) && preg_match($pattern, $url)) {
                $links[$url] = $url;
            }
        }
        ksort($links);

        return array_values($links);
    }

    public function chooseState(array $states, array $visited): string
    {
        $remaining = array_values(array_diff($states, $visited));
        if (! $remaining) {
            throw new RuntimeException('Every state overview has been covered. Continue with constituency rounds.');
        }

        return $remaining[0];
    }

    public function pollmedia(Brand $brand, array $state): array
    {
        $homepage = $this->reader->publicHtml($brand->website);
        $states = array_values(array_unique(array_map(fn ($url) => explode('?', $url, 2)[0], $this->links($homepage, $brand->website, '~/state/[^?]+(?:\?election=(?:pc|ac))?$~'))));
        sort($states);
        if (! $states) {
            throw new RuntimeException('The website has no state pages to rotate.');
        }
        $state += ['phase' => 'states', 'overviews' => [], 'constituencies' => [], 'coverage' => [], 'rosters' => [], 'cycle' => 1];
        $remaining = array_values(array_diff($states, $state['overviews']));
        if ($state['phase'] === 'states' && $remaining) {
            $url = $this->chooseState($states, $state['overviews']);
            $state['overviews'][] = $url;
        } else {
            if ($state['phase'] === 'states') {
                $state['phase'] = 'pc';
            }
            $url = null;
            foreach (array_unique([$state['phase'], 'ac']) as $kind) {
                $ordered = $states;
                usort($ordered, fn ($a, $b) => (($state['coverage'][$kind][$a] ?? 0) <=> ($state['coverage'][$kind][$b] ?? 0)) ?: strcmp($a, $b));
                foreach ($ordered as $stateUrl) {
                    if (! isset($state['rosters'][$kind][$stateUrl])) {
                        $state['rosters'][$kind][$stateUrl] = $this->constituencies($brand, $stateUrl, $kind);
                    }
                    foreach ($state['rosters'][$kind][$stateUrl] as $canonical) {
                        if (! in_array($canonical, $state['constituencies'][$kind] ?? [], true)) {
                            $url = $canonical;
                            $state['constituencies'][$kind][] = $url;
                            $state['coverage'][$kind][$stateUrl] = ($state['coverage'][$kind][$stateUrl] ?? 0) + 1;
                            $state['phase'] = $kind;
                            break 3;
                        }
                    }
                }
            }
            if (! $url) {
                return $this->pollmedia($brand, ['cycle' => $state['cycle'] + 1]);
            }
        }
        $label = rawurldecode(basename(parse_url($url, PHP_URL_PATH)));
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        $label = $query['name'] ?? ucwords(str_replace('-', ' ', $label));
        $report = $url.(str_contains($url, '?') ? '&' : '?').'format=report';
        $body = $label.' — original election report. Explore the recorded graphs, tables and source notes. Attached pages reproduce the website report; the complete report is in the link. Historical boundaries and coverage may vary. #ElectionData';

        return [['title' => $label.' | Original election report', 'body' => $body, 'source_url' => $report,
            'visual' => ['type' => 'source_report', 'report_url' => $report]], $state];
    }

    public function constituencies(Brand $brand, string $stateUrl, string $kind): array
    {
        $url = $stateUrl.'?election='.$kind;
        $visited = [];
        $roster = [];
        while ($url) {
            if (in_array($url, $visited, true)) {
                throw new RuntimeException('The constituency directory repeats a page. Coverage was not marked complete.');
            }
            $visited[] = $url;
            $html = $this->reader->publicHtml($url);
            foreach ($this->links($html, $brand->website, '~/constituency\?~') as $candidate) {
                parse_str(parse_url($candidate, PHP_URL_QUERY) ?? '', $query);
                if (($query['kind'] ?? '') === $kind && ! empty($query['state']) && ! empty($query['name'])) {
                    $roster[] = explode('?', $candidate, 2)[0].'?'.http_build_query(['kind' => $kind, 'state' => $query['state'], 'name' => $query['name']], '', '&', PHP_QUERY_RFC3986);
                }
            }
            $dom = new DOMDocument;
            @$dom->loadHTML($html, LIBXML_NONET);
            $url = null;
            foreach ((new DOMXPath($dom))->query('//a[@rel="next"]') as $anchor) {
                $next = explode('#', $anchor->getAttribute('href'), 2)[0];
                if (str_starts_with($next, '/') && ! str_starts_with($next, '//')) {
                    $next = rtrim($brand->website, '/').$next;
                }
                parse_str(parse_url($next, PHP_URL_QUERY) ?? '', $query);
                if (explode('?', $next, 2)[0] === $stateUrl && isset($query[$kind.'_page'])) {
                    $url = $next;
                    break;
                }
            }
        }

        return array_values(array_unique($roster));
    }

    public function pyp(Brand $brand): array
    {
        $feed = $this->reader->publicJson(rtrim($brand->website, '/').'/api/content-hub/daily-pyp');
        if (($feed['version'] ?? null) !== 1 || ($feed['source'] ?? '') !== 'online_exam_previous_year'
            || ($feed['date'] ?? '') !== now('Asia/Kolkata')->toDateString()
            || ! is_array($feed['posts'] ?? null) || count($feed['posts']) > 5) {
            throw new RuntimeException('The source must supply today’s Online Exam PYP selection, with at most five questions.');
        }
        $seen = [];
        foreach ($feed['posts'] as $package) {
            Validator::make($package, ['title' => 'required|string|max:200', 'body' => 'required|string|max:2000', 'source_url' => 'required|url:https|max:2048'])->validate();
            $source = $package['source_url'] ?? '';
            if (strtolower(parse_url($source, PHP_URL_HOST) ?? '') !== strtolower(parse_url($brand->website, PHP_URL_HOST) ?? '')
                || ! str_starts_with(parse_url($source, PHP_URL_PATH) ?? '', '/exam-detail/')) {
                throw new RuntimeException('Daily questions must link to the application’s Online Exam papers.');
            }
            $visual = app(ContentVisual::class)->validate($package['visual'] ?? null, $source);
            app(ContentVisual::class)->assertPreviousYearQuestion($visual, $source);
            $hash = hash('sha256', json_encode([$visual['question'], $visual['options']], JSON_UNESCAPED_UNICODE));
            if (in_array($hash, $seen, true)) {
                throw new RuntimeException('The daily feed repeats a question in the same batch.');
            }
            $seen[] = $hash;
        }
        if (! $feed['posts']) {
            throw new RuntimeException($feed['reason'] ?? 'No unused Online Exam PYP questions are available today.');
        }

        return $feed;
    }
}
