<?php

namespace App\Services\Research;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ContentVisual
{
    private function chartNumber(float $value): string
    {
        foreach ([1000000000000 => 'T', 1000000000 => 'B', 1000000 => 'M', 1000 => 'k'] as $divisor => $suffix) {
            if ($value >= $divisor) {
                return rtrim(rtrim(number_format($value / $divisor, 1, '.', ''), '0'), '.').$suffix;
            }
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    public function isHardQuestion(?array $visual): bool
    {
        if (($visual['type'] ?? '') === 'collection') {
            return ! empty($visual['cards']) && collect($visual['cards'])->every(fn ($card) => $this->isHardQuestion($card['visual'] ?? null));
        }

        return ($visual['type'] ?? '') === 'question' && ($visual['difficulty'] ?? '') === 'hard';
    }

    public function validate(mixed $input, ?string $sourceUrl): ?array
    {
        if ($input === null || $input === [] || (is_array($input) && ($input['type'] ?? '') === 'none')) {
            return null;
        }
        if (is_array($input) && ($input['type'] ?? '') === 'collection') {
            Validator::make($input, ['type' => 'required|in:collection', 'cards' => 'required|array|min:1|max:10', 'cards.*' => 'required|array:visual,source_url', 'cards.*.visual.type' => 'required|in:chart,question,facts', 'cards.*.source_url' => 'required|url:http,https|max:2048'])->validate();

            return ['type' => 'collection', 'cards' => array_values(array_map(fn ($card) => ['visual' => $this->validate($card['visual'], $card['source_url']), 'source_url' => $card['source_url']], $input['cards']))];
        }
        if (is_array($input) && ($input['type'] ?? '') === 'facts') {
            $data = Validator::make(['visual' => $input, 'source_url' => $sourceUrl], ['source_url' => 'required|url:http,https|max:2048', 'visual' => 'required|array:type,heading,rows,note', 'visual.type' => 'required|in:facts', 'visual.heading' => 'required|string|max:100', 'visual.rows' => 'required|array|min:1|max:6', 'visual.rows.*' => 'required|string|max:160', 'visual.note' => 'required|string|max:180'])->validate();

            return $data['visual'];
        }
        if (is_array($input) && ($input['type'] ?? '') === 'chart' && array_key_exists('series', $input)) {
            Validator::make($input, [
                'type' => 'required|in:chart', 'chart_style' => 'required|in:line',
                'values' => 'prohibited', 'history' => 'prohibited',
                'labels' => 'required|array|min:2|max:200',
                'series' => 'required|array|min:2|max:4', 'series.*' => 'required|array:name,values',
                'series.*.name' => 'required|string|max:35|distinct', 'series.*.values' => 'required|array',
            ])->validate();
            $base = $input;
            unset($base['series']);
            $series = [];
            foreach ($input['series'] as $item) {
                if (count($item['values']) !== count($input['labels'] ?? [])) {
                    throw ValidationException::withMessages(['visual.series' => 'Every series must have one value or null per year.']);
                }
                $checked = $this->validate($base + ['values' => $item['values']], $sourceUrl);
                $series[] = ['name' => $item['name'], 'values' => $checked['values']];
            }
            unset($checked['values']);

            return $checked + ['series' => $series];
        }
        $data = Validator::make(['visual' => $input], [
            'visual' => 'required|array:type,heading,question,options,answer,group,category,exam,year,topic,subtopic,difficulty,question_kind,paper_url,provenance_verified,chart_style,history,unit,labels,values,note',
            'visual.type' => 'required|in:question,chart',
        ])->validate()['visual'];
        $rules = ['source_url' => 'required|url:http,https|max:2048'];
        foreach (['group', 'category', 'exam', 'topic', 'subtopic'] as $key) {
            $rules['visual.'.$key] = 'nullable|string|max:45';
        }
        $rules['visual.year'] = 'nullable|integer|between:1900,2100';
        $rules['visual.difficulty'] = 'nullable|in:easy,medium,hard';
        if ($data['type'] === 'question') {
            $rules += ['visual.question_kind' => 'nullable|in:practice,pyp', 'visual.paper_url' => 'nullable|url:http,https|max:2048', 'visual.provenance_verified' => 'nullable|boolean'];
            if (! is_array($data['options'] ?? null)) {
                throw ValidationException::withMessages(['visual.options' => 'Supply between two and six answer options.']);
            }
            $seenBlank = false;
            foreach ($data['options'] as $option) {
                if ($option === null || $option === '') {
                    $seenBlank = true;
                } elseif ($seenBlank) {
                    throw ValidationException::withMessages(['visual.options' => 'Enter options consecutively, without empty gaps.']);
                }
            }
            $data['options'] = array_values(array_filter($data['options'] ?? [], fn ($value) => $value !== null && $value !== ''));
            $rules += ['visual.question' => 'required|string|max:650', 'visual.options' => 'required|array|min:2|max:6', 'visual.options.*' => 'required|string|max:150|distinct', 'visual.answer' => (($data['question_kind'] ?? '') === 'pyp' && ! empty($data['provenance_verified']) ? 'nullable' : 'required').'|integer|min:1|max:'.count($data['options'])];
            $keys = ['type', 'question', 'options', 'answer', 'group', 'category', 'exam', 'year', 'topic', 'subtopic', 'difficulty', 'question_kind', 'paper_url', 'provenance_verified'];
        } else {
            $line = ($data['chart_style'] ?? 'bar') === 'line';
            if ($line && ! empty($data['history'])) {
                Validator::make($data, ['history' => 'string|max:12000'])->validate();
                $data['labels'] = $data['values'] = [];
                foreach (preg_split('/\R/u', trim($data['history'])) as $row) {
                    $pair = array_map('trim', explode(',', $row));
                    if (count($pair) !== 2) {
                        throw ValidationException::withMessages(['visual.history' => 'Enter one year,value pair per line. Use a blank value for missing data.']);
                    }
                    $data['labels'][] = $pair[0];
                    $data['values'][] = $pair[1] === '' ? null : $pair[1];
                }
            }
            $labels = $data['labels'] ?? [];
            $values = $data['values'] ?? [];
            if (! is_array($labels) || ! is_array($values)) {
                throw ValidationException::withMessages(['visual' => 'Supply matching chart labels and numeric values.']);
            }
            $pairs = [];
            foreach (array_unique(array_merge(array_keys($labels), array_keys($values))) as $key) {
                if (($labels[$key] ?? '') !== '' && ($labels[$key] ?? null) !== null || ($values[$key] ?? '') !== '' && ($values[$key] ?? null) !== null) {
                    $pairs[] = [$labels[$key] ?? null, $values[$key] ?? null];
                }
            }
            $data['labels'] = array_column($pairs, 0);
            $data['values'] = array_column($pairs, 1);
            $limit = $line ? 200 : 6;
            $rules += ['visual.chart_style' => 'nullable|in:bar,line', 'visual.heading' => 'required|string|max:100', 'visual.unit' => 'required|string|max:20', 'visual.note' => 'required|string|max:180', 'visual.labels' => 'required|array|min:2|max:'.$limit, 'visual.labels.*' => $line ? 'required|integer|between:1800,2200|distinct' : 'required|string|max:35|distinct', 'visual.values' => 'required|array|min:2|max:'.$limit, 'visual.values.*' => ($line ? 'nullable' : 'required').'|numeric|min:0|max:1000000000000'];
            $keys = ['type', 'heading', 'unit', 'note', 'labels', 'values', 'chart_style'];
        }
        Validator::make(['visual' => $data, 'source_url' => $sourceUrl], $rules)->validate();
        if (($data['question_kind'] ?? '') === 'pyp' && $data['type'] === 'question') {
            $this->assertPreviousYearQuestion($data, $sourceUrl);
        }
        if ($data['type'] === 'chart' && ($data['chart_style'] ?? '') === 'line') {
            if (count(array_filter($data['values'], fn ($value) => $value !== null && $value !== '')) < 2) {
                throw ValidationException::withMessages(['visual.values' => 'Supply at least two recorded values; missing values are not zero.']);
            }
            $pairs = array_map(null, $data['labels'], $data['values']);
            usort($pairs, fn ($a, $b) => (int) $a[0] <=> (int) $b[0]);
            $data['labels'] = array_map(fn ($pair) => (string) (int) $pair[0], $pairs);
            $data['values'] = array_map(fn ($pair) => $pair[1] === null || $pair[1] === '' ? null : (float) $pair[1], $pairs);
        }
        if ($data['type'] === 'chart' && $data['unit'] === '%' && max($data['values']) > 100) {
            throw ValidationException::withMessages(['visual.values' => 'Percentage values must be between 0 and 100.']);
        }

        return array_intersect_key($data, array_flip($keys));
    }

    public function assertPreviousYearQuestion(?array $visual, ?string $sourceUrl): void
    {
        if (($visual['type'] ?? '') === 'collection') {
            $checked = $this->validate($visual, $sourceUrl);
            foreach ($checked['cards'] as $card) {
                $this->assertPreviousYearQuestion($card['visual'], $card['source_url']);
            }

            return;
        }
        $valid = ($visual['type'] ?? '') === 'question'
            && ($visual['question_kind'] ?? '') === 'pyp'
            && ! empty($visual['exam']) && ! empty($visual['year'])
            && in_array($visual['provenance_verified'] ?? false, [true, 1, '1'], true)
            && filter_var($visual['paper_url'] ?? '', FILTER_VALIDATE_URL)
            && filter_var($sourceUrl ?? '', FILTER_VALIDATE_URL);
        if (! $valid) {
            throw ValidationException::withMessages(['visual' => 'Previous-year questions only: supply the exact question and options, exam name, exam year, source paper URL, and confirmation that the source was checked. Original or AI-invented practice questions cannot be published.']);
        }
        Validator::make(['visual' => $visual, 'source_url' => $sourceUrl], [
            'visual.question' => 'required|string', 'visual.options' => 'required|array|min:2|max:6',
            'visual.exam' => 'required|string|max:45', 'visual.year' => 'required|integer|between:1900,'.now()->year,
            'visual.paper_url' => 'required|url:http,https|max:2048', 'source_url' => 'required|url:http,https|max:2048',
        ])->validate();
    }

    public function hashtags(array $visual): string
    {
        $tags = [];
        foreach (['group', 'category', 'exam', 'year', 'topic', 'subtopic'] as $key) {
            $value = (string) ($visual[$key] ?? '');
            if ($key === 'year' && $value !== '') {
                $value = 'Exam'.$value;
            }
            $value = preg_replace('/[^\p{L}\p{N}_]/u', '', $value);
            if ($value !== '') {
                $tags[] = '#'.mb_substr($value, 0, 40);
            }
        }

        return implode(' ', array_slice(array_unique($tags), 0, 6));
    }

    public function caption(string $body, ?array $visual): string
    {
        if (($visual['type'] ?? '') === 'collection') {
            foreach ($visual['cards'] as $card) {
                $body = $this->caption($body, $card['visual']);
            }

            return $body;
        }
        if (($visual['type'] ?? '') !== 'question') {
            return $body;
        }
        if (($visual['question_kind'] ?? '') === 'pyp' && ! empty($visual['exam']) && ! empty($visual['year'])) {
            $provenance = 'Asked in '.$visual['exam'].' · '.$visual['year'];
            if (! str_contains($body, $provenance)) {
                $body = $provenance."\n\n".$body;
            }
        }
        $tags = $this->hashtags($visual);
        $missing = array_filter(explode(' ', $tags), fn ($tag) => $tag !== '' && ! in_array($tag, preg_split('/\s+/u', $body), true));

        return $body.($missing ? "\n\n".implode(' ', $missing) : '');
    }

    /** Pixel positions and literal text; never include the answer on a question card. */
    public function layout(array $visual, string $brand, string $sourceUrl): array
    {
        if (! empty($visual['series'])) {
            return $this->comparisonLayout($visual, $brand, $sourceUrl);
        }
        $layers = [[56, 28, 1088, 44, 24, '#ffffff', $brand, true]];
        $bars = [];
        $lines = [];
        $missingMarkers = [];
        $points = [];
        $panels = [];
        $backgrounds = [[0, 0, 1200, 96, '#102d49'], [0, 96, 1200, 103, '#14b8a6']];
        if ($visual['type'] === 'facts') {
            $layers[] = [56, 125, 1088, 110, 34, '#102d49', $visual['heading'], true];
            foreach ($visual['rows'] as $i => $row) {
                $y = 260 + $i * 105;
                $panels[] = [56, $y, 1144, $y + 90];
                $layers[] = [78, $y + 16, 1044, 65, 24, '#102d49', $row];
            }
            $bottom = 280 + count($visual['rows']) * 105;
            $layers[] = [56, $bottom, 1088, 95, 20, '#52657b', $visual['note']];
            $height = $bottom + 180;
        } elseif ($visual['type'] === 'question') {
            $provenance = ! empty($visual['exam'])
                ? (($visual['question_kind'] ?? '') === 'pyp' ? 'ASKED IN: ' : 'EXAM: ').$visual['exam'].(! empty($visual['year']) ? ' · '.$visual['year'] : '')
                : 'PRACTICE QUESTION · Exam not supplied';
            $layers[] = [56, 126, 1088, 55, 23, '#087f8c', $provenance, true];
            $context = array_unique(array_filter([$visual['group'] ?? null, $visual['category'] ?? null, $visual['topic'] ?? null, $visual['subtopic'] ?? null]));
            $layers[] = [56, 188, 1088, 52, 20, '#52657b', implode(' · ', $context)];
            $questionHeight = min(300, max(130, (int) ceil(mb_strlen($visual['question']) / 65) * 48));
            $layers[] = [56, 260, 1088, $questionHeight, 32, '#102d49', $visual['question'], true];
            $optionsTop = 260 + $questionHeight + 24;
            $optionHeight = max(array_map('mb_strlen', $visual['options'])) > 75 ? 150 : 106;
            foreach ($visual['options'] as $i => $option) {
                $x = 56 + ($i % 2) * 556;
                $y = $optionsTop + intdiv($i, 2) * ($optionHeight + 18);
                $panels[] = [$x, $y, $x + 532, $y + $optionHeight];
                $layers[] = [$x + 20, $y + 22, 42, 46, 26, '#087f8c', chr(65 + $i).'.', true];
                $layers[] = [$x + 76, $y + 22, 430, $optionHeight - 35, 25, '#102d49', $option];
            }
            $footer = $optionsTop + (int) ceil(count($visual['options']) / 2) * ($optionHeight + 18) + 12;
            $layers[] = [56, $footer, 1088, 42, 22, '#087f8c', 'Choose your answer · Explore the linked learning resource', true];
            $height = $footer + 130;
        } elseif (($visual['chart_style'] ?? '') === 'line') {
            $layers[] = [56, 125, 1088, 110, 34, '#102d49', $visual['heading'], true];
            $max = $visual['unit'] === '%' ? 100 : max(1, ...array_filter($visual['values'], fn ($v) => $v !== null));
            $firstYear = (int) min($visual['labels']);
            $lastYear = (int) max($visual['labels']);
            for ($tick = 0; $tick <= 4; $tick++) {
                $y = 620 - $tick * 85;
                $backgrounds[] = [130, $y, 1080, $y + 1, '#dbe5ee'];
                $layers[] = [20, $y - 12, 100, 32, 16, '#52657b', $this->chartNumber($max * $tick / 4)];
            }
            $previous = null;
            $labelledPoints = [];
            $lastLabelX = -100;
            $count = count($visual['labels']);
            foreach ($visual['labels'] as $i => $year) {
                $x = 130 + (int) round(950 * ((int) $year - $firstYear) / max(1, $lastYear - $firstYear));
                if ($i === $count - 1 || ($x - $lastLabelX >= 80 && ($i === 0 || 1080 - $x >= 80))) {
                    $layers[] = [$x - 25, 644, 70, 34, 17, '#52657b', (string) $year];
                    $lastLabelX = $x;
                }
                $value = $visual['values'][$i];
                if ($value === null) {
                    $missingMarkers[] = [$x, 632];

                    continue;
                }
                $y = 620 - (int) round(340 * $value / $max);
                $points[] = [$x, $y];
                if ($previous !== null) {
                    $lines[] = [$previous[0], $previous[1], $x, $y];
                }
                $labelledPoints[] = [$x, $y, $value];
                $previous = [$x, $y];
            }
            // Reserve the last observation, then label spaced observations without overlapping boxes.
            $last = array_key_last($labelledPoints);
            $selected = [$labelledPoints[$last]];
            foreach ($labelledPoints as $i => $point) {
                if ($i === $last) {
                    continue;
                }
                $overlaps = false;
                foreach ($selected as $other) {
                    if (abs($point[0] - $other[0]) < 145) {
                        $overlaps = true;
                        break;
                    }
                }
                if (! $overlaps) {
                    $selected[] = $point;
                }
            }
            foreach ($selected as [$x, $y, $value]) {
                $layers[] = [min(1004, max(130, $x - 55)), $y - 38, 140, 30, 17, '#087f8c', $this->chartNumber($value).($visual['unit'] === '%' ? '%' : ''), true];
            }
            $layers[] = [56, 702, 1088, 44, 19, '#087f8c', 'Year · '.$firstYear.'–'.$lastYear.' · '.count($points).' recorded values · Unit: '.$visual['unit']];
            if ($missingMarkers) {
                $layers[] = [56, 750, 1088, 35, 18, '#52657b', '× on year axis = data unavailable. Line bridges gaps; no value is estimated.'];
            }
            $layers[] = [56, $missingMarkers ? 792 : 754, 1088, 95, 20, '#52657b', $visual['note']];
            $height = $missingMarkers ? 980 : 940;
        } else {
            $layers[] = [56, 125, 1088, 110, 34, '#102d49', $visual['heading'], true];
            $max = $visual['unit'] === '%' ? 100 : max(1, ...$visual['values']);
            $count = count($visual['labels']);
            $rowHeight = $count <= 3 ? 125 : 90;
            $bottom = 285 + $count * $rowHeight;
            for ($tick = 0; $tick <= 4; $tick++) {
                $x = 310 + (int) round(650 * $tick / 4);
                $backgrounds[] = [$x, 270, $x + 1, $bottom - 20, '#dbe5ee'];
                $layers[] = [$x - 12, $bottom, 150, 40, 17, '#52657b', (string) round($max * $tick / 4, 2)];
            }
            foreach ($visual['labels'] as $i => $label) {
                $y = 290 + $i * $rowHeight;
                $value = (float) $visual['values'][$i];
                $layers[] = [56, $y + 6, 230, 65, 24, '#102d49', $label, true];
                $backgrounds[] = [310, $y, 960, $y + 55, '#e9f0f6'];
                $width = (int) round(650 * $value / $max);
                if ($width > 0) {
                    $bars[] = [310, $y, 310 + $width, $y + 55];
                }
                $layers[] = [982, $y + 6, 162, 65, 24, '#087f8c', (string) $visual['values'][$i].' '.$visual['unit'], true];
            }
            $layers[] = [56, $bottom + 58, 1088, 36, 19, '#087f8c', 'Scale starts at zero · Unit: '.$visual['unit']];
            $layers[] = [56, $bottom + 105, 1088, 90, 20, '#52657b', $visual['note']];
            $height = $bottom + 280;
        }
        $backgrounds[] = [0, $height - 65, 1200, $height, '#102d49'];
        $layers[] = [56, $height - 47, 1088, 35, 18, '#ffffff', 'Source: '.parse_url($sourceUrl, PHP_URL_HOST)];

        return ['width' => 1200, 'height' => $height, 'layers' => $layers, 'bars' => $bars, 'lines' => $lines, 'missing_markers' => $missingMarkers, 'points' => $points, 'panels' => $panels, 'backgrounds' => $backgrounds];
    }

    private function comparisonLayout(array $visual, string $brand, string $sourceUrl): array
    {
        $colors = ['#e87924', '#2563eb', '#059669', '#9333ea'];
        $backgrounds = [[0, 0, 1200, 210, '#102d49'], [0, 210, 1200, 218, '#22c6b8'], [36, 390, 1164, 896, '#ffffff']];
        $layers = [[52, 28, 1096, 40, 22, '#7de0d6', mb_substr($brand, 0, 55), true], [52, 83, 1096, 110, 37, '#ffffff', $visual['heading'], true]];
        $lines = $points = $missing = $panels = [];
        $first = (int) min($visual['labels']);
        $last = (int) max($visual['labels']);
        $allValues = array_merge(...array_column($visual['series'], 'values'));
        $max = $visual['unit'] === '%' ? 100 : max(1, ...array_filter($allValues, fn ($v) => $v !== null));
        if ($visual['unit'] !== '%') {
            $magnitude = 10 ** floor(log10($max));
            $max = ceil($max / $magnitude) * $magnitude;
        }
        foreach ($visual['series'] as $index => $series) {
            $color = $colors[$index];
            $x = 52 + $index * (int) (1096 / count($visual['series']));
            $width = (int) (1096 / count($visual['series'])) - 16;
            $panels[] = [$x, 244, $x + $width, 367];
            $backgrounds[] = [$x, 244, $x + 5, 367, $color];
            $latest = array_key_last(array_filter($series['values'], fn ($v) => $v !== null));
            $suffix = $visual['unit'] === '%' ? '%' : '';
            $layers[] = [$x + 18, 256, $width - 32, 35, 19, $color, $series['name'], true];
            $layers[] = [$x + 18, 300, $width - 32, 48, 27, '#102d49', $this->chartNumber($series['values'][$latest]).$suffix.' · '.$visual['labels'][$latest], true];
        }
        $layers[] = [52, 400, 1096, 32, 17, '#52657b', 'HISTORICAL COMPARISON  /  '.$first.'–'.$last.'  /  '.$visual['unit']];
        for ($tick = 0; $tick <= 4; $tick++) {
            $y = 810 - $tick * 85;
            $backgrounds[] = [150, $y, 1080, $y + 1, '#e4ebf2'];
            $layers[] = [52, $y - 12, 92, 30, 16, '#52657b', $this->chartNumber($max * $tick / 4)];
        }
        $lastLabelX = -100;
        foreach ($visual['labels'] as $index => $year) {
            $x = 150 + (int) round(930 * ((int) $year - $first) / max(1, $last - $first));
            if ($index === count($visual['labels']) - 1 || ($x - $lastLabelX >= 90 && 1080 - $x >= 90)) {
                $layers[] = [$x - 25, 850, 75, 30, 16, '#52657b', (string) $year];
                $lastLabelX = $x;
            }
        }
        foreach ($visual['series'] as $index => $series) {
            $previous = null;
            foreach ($series['values'] as $i => $value) {
                $x = 150 + (int) round(930 * ((int) $visual['labels'][$i] - $first) / max(1, $last - $first));
                if ($value === null) {
                    $missing[] = [$x, 826 + $index * 5, $colors[$index]];

                    continue;
                }
                $y = 810 - (int) round(340 * $value / $max);
                $points[] = [$x, $y, $colors[$index]];
                if ($previous !== null) {
                    $lines[] = [$previous[0], $previous[1], $x, $y, $colors[$index]];
                }
                $previous = [$x, $y];
            }
        }
        $layers[] = [52, 925, 1096, 42, 17, '#52657b', $missing ? '× = unavailable in that series. Lines bridge gaps; missing values are not estimated.' : 'Shared scale starts at zero. Values shown above are the latest recorded observations.'];
        $layers[] = [52, 980, 1096, 104, 19, '#52657b', $visual['note']];
        $backgrounds[] = [0, 1116, 1200, 1180, '#102d49'];
        $layers[] = [52, 1134, 1096, 32, 18, '#ffffff', 'EXPLORE THE DATA  →  '.parse_url($sourceUrl, PHP_URL_HOST)];

        return ['width' => 1200, 'height' => 1180, 'layers' => $layers, 'bars' => [], 'lines' => $lines, 'missing_markers' => $missing, 'points' => $points, 'panels' => $panels, 'backgrounds' => $backgrounds];
    }
}
