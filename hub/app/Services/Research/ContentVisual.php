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
        $layers = [[60, 45, 1080, 50, 24, '#157f79', $brand]];
        $bars = [];
        $panels = [];
        if ($visual['type'] === 'question') {
            $context = array_filter([$visual['exam'] ?? null, $visual['year'] ?? null, $visual['topic'] ?? null]);
            $layers[] = [60, 105, 1080, 65, 22, '#536477', implode(' · ', $context)];
            $layers[] = [60, 190, 1080, 300, 32, '#142a40', $visual['question']];
            foreach ($visual['options'] as $i => $option) {
                $panels[] = [60, 500 + $i * 82, 1140, 578 + $i * 82];
                $layers[] = [85, 510 + $i * 82, 1030, 72, 25, '#142a40', chr(65 + $i).'.  '.$option];
            }
            $layers[] = [60, 1020, 1080, 45, 23, '#157f79', 'Choose your answer · Open the linked question to practise'];
        } else {
            $layers[] = [60, 115, 1080, 140, 35, '#142a40', $visual['heading']];
            $max = $visual['unit'] === '%' ? 100 : max(1, ...$visual['values']);
            foreach ($visual['labels'] as $i => $label) {
                $y = 290 + $i * 105;
                $value = (float) $visual['values'][$i];
                $layers[] = [60, $y, 230, 70, 24, '#142a40', $label];
                $width = (int) round(650 * $value / $max);
                if ($width > 0) {
                    $bars[] = [310, $y, 310 + $width, $y + 45];
                }
                $layers[] = [975, $y, 180, 75, 21, '#142a40', (string) $visual['values'][$i].' '.$visual['unit']];
            }
            $layers[] = [60, 950, 1080, 110, 21, '#536477', 'Zero baseline · '.$visual['note']];
        }
        $layers[] = [60, 1110, 1080, 50, 19, '#536477', 'Source: '.parse_url($sourceUrl, PHP_URL_HOST)];

        return ['layers' => $layers, 'bars' => $bars, 'panels' => $panels];
    }
}
