<?php

namespace App\Services\Research;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PollmediaChart
{
    public const GRAPHS = ['turnout', 'leaders', 'shares', 'margin', 'electorate'];

    public function select(string $html, string $url, int $cursor = 0): ?array
    {
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        $kind = ($query['kind'] ?? $query['election'] ?? 'pc') === 'ac' ? 'ac' : 'pc';
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        $xpath = new DOMXPath($document);
        $scope = str_contains(parse_url($url, PHP_URL_PATH) ?? '', '/state/') ? '//*[@id="'.$kind.'-history"]' : '//main';
        $available = [];
        foreach ($xpath->query($scope.'//script[contains(concat(" ",normalize-space(@class)," ")," history-chart-data ")]') as $script) {
            $data = json_decode($script->textContent, true);
            if (! is_array($data) || ! is_array($data['rows'] ?? null) || count($data['rows']) > 200) {
                continue;
            }
            $keys = array_column($data['series'] ?? [], 'key');
            $key = match (true) {
                $keys === ['turnout'] => 'turnout',
                $keys === ['margin'] => 'margin',
                $keys === ['electors', 'polled'] => 'electorate',
                isset($keys[0]) && str_starts_with($keys[0], 'fixed') => 'leaders',
                $keys === ['party0_share', 'party1_share', 'others_share'] => 'shares',
                default => null,
            };
            if (! $key) {
                continue;
            }
            $rows = $data['rows'];
            usort($rows, fn ($a, $b) => ($a['year'] ?? 0) <=> ($b['year'] ?? 0));
            $series = [];
            foreach (array_slice($data['series'], 0, 4) as $item) {
                $series[] = ['name' => $item['label'] ?? $item['key'],
                    'values' => array_map(fn ($row) => $row[$item['key']] ?? null, $rows),
                    'names' => array_map(fn ($row) => isset($item['name_key']) ? ($row[$item['name_key']] ?? '') : '', $rows)];
            }
            try {
                $available[$key] = $this->validate(['type' => 'chart_video', 'graph_key' => $key, 'election' => $kind,
                    'heading' => match ($key) {
                        'turnout' => 'Voter turnout', 'leaders' => 'Top three parties across the years',
                        'shares' => 'Party vote shares by year', 'margin' => 'Mean winning margin', default => 'Registered electors and votes polled',
                    }, 'unit' => in_array($key, ['turnout', 'leaders', 'shares'], true) ? '%' : 'votes',
                    'labels' => array_column($rows, 'year'), 'series' => $series,
                    'note' => 'Available-table aggregates; coverage and boundaries vary. Missing values are not zero.']);
            } catch (ValidationException) {
                continue;
            }
        }
        for ($offset = 0; $offset < count(self::GRAPHS); $offset++) {
            $index = ($cursor + $offset) % count(self::GRAPHS);
            if (isset($available[self::GRAPHS[$index]])) {
                return ['visual' => $available[self::GRAPHS[$index]], 'next_cursor' => $index + 1];
            }
        }

        return null;
    }

    public function validate(array $input): array
    {
        $data = Validator::make($input, [
            'type' => 'required|in:chart_video', 'graph_key' => 'required|in:'.implode(',', self::GRAPHS),
            'election' => 'required|in:pc,ac', 'heading' => 'required|string|max:100', 'unit' => 'required|in:%,votes',
            'note' => 'required|string|max:180', 'labels' => 'required|array|min:2|max:200',
            'labels.*' => 'required|integer|between:1800,2200|distinct', 'series' => 'required|array|min:1|max:4',
            'series.*.name' => 'required|string|max:60', 'series.*.values' => 'required|array',
            'series.*.values.*' => 'nullable|numeric|min:0|max:'.(($input['unit'] ?? '') === '%' ? '100' : '1000000000000'),
            'series.*.names' => 'required|array', 'series.*.names.*' => 'nullable|string|max:60',
        ])->validate();
        $count = count($data['labels']);
        $usable = [];
        foreach ($data['series'] as $series) {
            if (count($series['values']) !== $count || count($series['names']) !== $count) {
                throw ValidationException::withMessages(['visual' => 'Each chart series must match its election years.']);
            }
            foreach ($series['values'] as $i => $value) {
                if ($value !== null) {
                    $usable[$i] = true;
                }
            }
        }
        if (count($usable) < 2) {
            throw ValidationException::withMessages(['visual' => 'A historical video needs at least two recorded years.']);
        }

        return $data;
    }

    public function package(array $package, int $cursor): array
    {
        $url = $package['source_url'];
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        unset($query['format']);
        if (! isset($query['kind'])) {
            $query['election'] = $query['election'] ?? 'pc';
        }
        $url = explode('?', $url, 2)[0].'?'.http_build_query($query);
        $selection = $this->select(app(FetchSource::class)->publicHtml($url), $url, $cursor);
        if (! $selection) {
            return [$package, $cursor];
        }
        $visual = $selection['visual'];
        $place = explode(' | ', $package['title'])[0];
        $election = $visual['election'] === 'pc' ? 'Lok Sabha' : 'Assembly';
        $years = min($visual['labels']).'–'.max($visual['labels']);
        $package['video'] = ['source_url' => $url, 'visual' => $visual,
            'title' => mb_substr($place.' | '.$visual['heading'], 0, 200),
            'body' => $place.' '.$election.': '.$visual['heading'].' ('.$years.").\n\nWatch the recorded history unfold. Available-table aggregates; coverage and boundaries vary. Full data and source notes in the link. #Pollmedia"];

        return [$package, $selection['next_cursor']];
    }
}
