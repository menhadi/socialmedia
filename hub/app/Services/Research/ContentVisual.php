<?php

namespace App\Services\Research;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ContentVisual
{
    public function validate(mixed $input, ?string $sourceUrl): ?array
    {
        if ($input === null || $input === [] || (is_array($input) && ($input['type'] ?? '') === 'none')) {
            return null;
        }
        $data = Validator::make(['visual' => $input], [
            'visual' => 'required|array:type,heading,question,options,answer,group,category,exam,year,topic,subtopic,difficulty,unit,labels,values,note',
            'visual.type' => 'required|in:question,chart',
        ])->validate()['visual'];
        $rules = ['source_url' => 'required|url:http,https|max:2048'];
        foreach (['group', 'category', 'exam', 'topic', 'subtopic'] as $key) {
            $rules['visual.'.$key] = 'nullable|string|max:45';
        }
        $rules['visual.year'] = 'nullable|integer|between:1900,2100';
        $rules['visual.difficulty'] = 'nullable|in:easy,medium,hard';
        if ($data['type'] === 'question') {
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
            $rules += ['visual.question' => 'required|string|max:650', 'visual.options' => 'required|array|min:2|max:6', 'visual.options.*' => 'required|string|max:150|distinct', 'visual.answer' => 'required|integer|min:1|max:'.count($data['options'])];
            $keys = ['type', 'question', 'options', 'answer', 'group', 'category', 'exam', 'year', 'topic', 'subtopic', 'difficulty'];
        } else {
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
            $rules += ['visual.heading' => 'required|string|max:100', 'visual.unit' => 'required|string|max:20', 'visual.note' => 'required|string|max:180', 'visual.labels' => 'required|array|min:2|max:6', 'visual.labels.*' => 'required|string|max:35|distinct', 'visual.values' => 'required|array|min:2|max:6', 'visual.values.*' => 'required|numeric|min:0|max:1000000000000'];
            $keys = ['type', 'heading', 'unit', 'note', 'labels', 'values'];
        }
        Validator::make(['visual' => $data, 'source_url' => $sourceUrl], $rules)->validate();
        if ($data['type'] === 'chart' && $data['unit'] === '%' && max($data['values']) > 100) {
            throw ValidationException::withMessages(['visual.values' => 'Percentage values must be between 0 and 100.']);
        }

        return array_intersect_key($data, array_flip($keys));
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
        if (($visual['type'] ?? '') !== 'question') {
            return $body;
        }
        $tags = $this->hashtags($visual);
        $missing = array_filter(explode(' ', $tags), fn ($tag) => $tag !== '' && ! in_array($tag, preg_split('/\s+/u', $body), true));

        return $body.($missing ? "\n\n".implode(' ', $missing) : '');
    }

    /** Pixel positions and literal text; never include the answer on a question card. */
    public function layout(array $visual, string $brand, string $sourceUrl): array
    {
        $layers = [[56, 28, 1088, 44, 24, '#ffffff', $brand, true]];
        $bars = [];
        $panels = [];
        $backgrounds = [[0, 0, 1200, 96, '#102d49'], [0, 96, 1200, 103, '#14b8a6']];
        if ($visual['type'] === 'question') {
            $provenance = ! empty($visual['exam'])
                ? 'EXAM: '.$visual['exam'].(! empty($visual['year']) ? ' · '.$visual['year'] : '')
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

        return ['width' => 1200, 'height' => $height, 'layers' => $layers, 'bars' => $bars, 'panels' => $panels, 'backgrounds' => $backgrounds];
    }
}
