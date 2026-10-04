<?php

namespace App\Services\Research;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class ChartVideo
{
    public function create(array $package): array
    {
        $visual = app(PollmediaChart::class)->validate($package['visual']);
        $ffmpeg = config('research.ffmpeg');
        $font = config('research.chart_font');
        if (! is_string($ffmpeg) || ! is_file($ffmpeg) || ! is_string($font) || ! is_file($font) || ! function_exists('imagettftext')) {
            throw new RuntimeException('Chart video needs FFmpeg, PHP GD with FreeType and HUB_CHART_FONT. Install FFmpeg and retry this daily batch.');
        }
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hub-chart-'.Str::uuid();
        File::makeDirectory($directory, 0700);
        try {
            $count = count($visual['labels']);
            $story = isset($visual['history']);
            $seconds = $story ? min(2, 60 / $count) : max(10, min(25, $count)) / $count;
            $this->frame($package, $count - 1, true, $directory.'/complete.png');
            $list = "file 'complete.png'\nduration 2\n";
            for ($i = 0; $i < $count; $i++) {
                $this->frame($package, $i, false, $directory.'/year-'.$i.'.png');
                $list .= "file 'year-{$i}.png'\nduration ".sprintf('%.6F', $seconds)."\n";
            }
            $ending = $story ? 'comparison.png' : 'complete.png';
            if ($story) {
                $this->historyFrame($package, $count - 1, true, $directory.'/'.$ending);
            }
            $hold = $story ? 6 : 3;
            $list .= "file '{$ending}'\nduration {$hold}\nfile '{$ending}'\n";
            File::put($directory.'/frames.txt', $list);
            $duration = 2 + $hold + $count * $seconds;
            $process = new Process([$ffmpeg, '-hide_banner', '-loglevel', 'error', '-y', '-f', 'concat', '-safe', '1',
                '-i', 'frames.txt', '-an', '-c:v', 'libx264', '-preset', 'fast', '-crf', '21', '-pix_fmt', 'yuv420p',
                '-vf', 'fps=24,tpad=stop_mode=clone:stop_duration=3', '-r', '24', '-t', (string) $duration, '-movflags', '+faststart', 'video.mp4'], $directory);
            try {
                $process->setTimeout(180)->mustRun();
            } catch (\Throwable $error) {
                throw new RuntimeException('Chart video encoding failed. Check FFmpeg/libx264 and temporary disk space; no post was submitted.', 0, $error);
            }
            $bytes = File::get($directory.'/video.mp4');
            if (strlen($bytes) < 1000 || strlen($bytes) > 40 * 1024 * 1024 || substr($bytes, 4, 4) !== 'ftyp') {
                throw new RuntimeException('The chart video is missing or exceeds the publishing limit.');
            }
            $hash = hash('sha256', $bytes);
            $path = 'chart-videos/'.$hash.'.mp4';
            if (! Storage::disk('local')->put($path, $bytes)) {
                throw new RuntimeException('The chart video could not be saved.');
            }
            Storage::disk('local')->put('chart-videos/'.$hash.'.png', File::get($directory.'/complete.png'));

            return ['video_path' => $path, 'video_hash' => $hash, 'image_path' => null, 'image_hash' => null, 'card_images' => null];
        } finally {
            File::deleteDirectory($directory);
        }
    }

    private function number(float $value, string $unit): string
    {
        if ($unit === '%') {
            return number_format($value, 2).'%';
        }
        if ($value >= 1000000) {
            return number_format($value / 1000000, 2).'M';
        }
        if ($value >= 1000) {
            return number_format($value / 1000, 1).'k';
        }

        return number_format($value);
    }

    public function frame(array $package, int $index, bool $complete, string $path): void
    {
        $visual = app(PollmediaChart::class)->validate($package['visual']);
        if (isset($visual['history'])) {
            $this->historyFrame($package, $index, false, $path);

            return;
        }
        $image = imagecreatetruecolor(1280, 720);
        $background = imagecolorallocate($image, 12, 22, 42);
        $white = imagecolorallocate($image, 238, 243, 252);
        $muted = imagecolorallocate($image, 164, 182, 205);
        $grid = imagecolorallocate($image, 40, 58, 80);
        $colors = [imagecolorallocate($image, 45, 216, 190), imagecolorallocate($image, 255, 191, 95), imagecolorallocate($image, 144, 173, 255), imagecolorallocate($image, 233, 132, 193)];
        imagefill($image, 0, 0, $background);
        $font = config('research.chart_font');
        $text = function (string $value, int $x, int $y, int $size, int $color, int $width = 1170) use ($image, $font): void {
            while ($size > 11) {
                $box = imagettfbbox($size, 0, $font, $value);
                if ($width >= $box[2] - $box[0]) {
                    break;
                }
                $size--;
            }
            imagettftext($image, $size, 0, $x, $y, $color, $font, $value);
        };
        $text('POLLMEDIA  /  HISTORICAL ELECTION DATA', 55, 37, 14, $colors[0]);
        $text($package['title'], 55, 86, 28, $white);
        $years = $visual['labels'];
        $count = count($years);
        $index = min($count - 1, max(0, $index));
        $text(($visual['election'] === 'pc' ? 'Lok Sabha' : 'Assembly').'  |  '.min($years).'–'.max($years).'  |  '.($complete ? 'Full recorded history' : 'Election year '.$years[$index]), 55, 123, 17, $muted);
        $all = array_merge(...array_column($visual['series'], 'values'));
        $maximum = $visual['unit'] === '%' ? 100 : max(1, max(array_filter($all, fn ($v) => $v !== null)) * 1.1);
        $left = 120;
        $right = 1210;
        $top = 245;
        $bottom = 555;
        for ($tick = 0; $tick <= 4; $tick++) {
            $y = (int) round($bottom - ($bottom - $top) * $tick / 4);
            imageline($image, $left, $y, $right, $y, $grid);
            $text($this->number($maximum * $tick / 4, $visual['unit']), 25, $y + 5, 12, $muted, 90);
        }
        $minYear = min($years);
        $span = max(1, max($years) - $minYear);
        foreach ($years as $i => $year) {
            $x = (int) round($left + ($year - $minYear) / $span * ($right - $left));
            if ($i === 0 || $i === $count - 1 || ($i % max(1, (int) ceil($count / 7)) === 0 && $i < $count - 2)) {
                $text((string) $year, $x - 20, $bottom + 28, 12, $muted);
            }
        }
        foreach ($visual['series'] as $s => $series) {
            $color = $colors[$s];
            $name = $series['names'][$index] ?: $series['name'];
            $value = $series['values'][$index];
            $text($name.': '.($value === null ? 'not recorded' : $this->number((float) $value, $visual['unit'])), 55 + ($s % 2) * 600, 168 + (int) floor($s / 2) * 35, 17, $color, 570);
            $previous = null;
            imagesetthickness($image, 3);
            for ($i = 0; $i <= $index; $i++) {
                $value = $series['values'][$i];
                if ($value === null) {
                    $previous = null;

                    continue;
                }
                $x = (int) round($left + ($years[$i] - $minYear) / $span * ($right - $left));
                $y = (int) round($bottom - $value / $maximum * ($bottom - $top));
                if ($previous) {
                    imageline($image, $previous[0], $previous[1], $x, $y, $color);
                }
                imagefilledellipse($image, $x, $y, 9, 9, $color);
                $previous = [$x, $y];
            }
        }
        $text($visual['graph_key'] === 'shares' ? 'Lines follow party rank each year; party names can change.' : 'Explore the complete charts, tables and source notes on Pollmedia.', 55, 625, 16, $white);
        $text($visual['note'], 55, 660, 13, $muted);
        $text('Source: '.$package['source_url'], 55, 690, 13, $muted);
        imagepng($image, $path);
        imagedestroy($image);
    }

    private function historyFrame(array $package, int $index, bool $comparison, string $path): void
    {
        $v = $package['visual'];
        $image = imagecreatetruecolor(1280, 720);
        $bg = imagecolorallocate($image, 10, 24, 38);
        $panel = imagecolorallocate($image, 19, 41, 54);
        $white = imagecolorallocate($image, 237, 244, 244);
        $muted = imagecolorallocate($image, 164, 182, 191);
        $teal = imagecolorallocate($image, 108, 221, 199);
        $gold = imagecolorallocate($image, 241, 207, 135);
        imagefill($image, 0, 0, $bg);
        $text = function (string $value, int $x, int $y, int $size = 22, ?int $color = null, int $width = 1160) use ($image, $white): void {
            $font = config('research.chart_font');
            while ($size > 11 && imagettfbbox($size, 0, $font, $value)[2] > $width) {
                $size--;
            }
            imagettftext($image, $size, 0, $x, $y, $color ?? $white, $font, $value);
        };
        parse_str(parse_url($package['source_url'], PHP_URL_QUERY) ?? '', $query);
        $place = $query['name'] ?? 'Constituency';
        $years = $v['labels'];
        $values = $v['series'][0]['values'];
        $rows = $v['history'];
        $row = $rows[$index];
        $text('pollmedia. / CONSTITUENCY HISTORY', 48, 48, 19, $teal);
        $text($place.' | How voting changed', 48, 110, 32);
        $text(($v['election'] === 'pc' ? 'Lok Sabha' : 'Assembly').' | '.min($years).'–'.max($years).' | Reported election records', 48, 151, 18, $muted);
        $format = fn ($n, $percent = false) => $n === null ? 'Not recorded' : number_format($n, $percent ? 2 : 0).($percent ? '%' : '');
        if ($comparison) {
            $a = count($years) - 2;
            $b = $a + 1;
            $text('The recent comparison: '.$years[$a].' → '.$years[$b], 48, 227, 26, $gold);
            foreach ([['Turnout', $values[$a], $values[$b], true], ['Votes polled', $rows[$a]['polled'], $rows[$b]['polled'], false], ['Winning margin', $rows[$a]['margin'], $rows[$b]['margin'], false]] as $i => [$label, $before, $after, $percent]) {
                $y = 280 + $i * 80;
                imagefilledrectangle($image, 48, $y - 25, 1232, $y + 35, $panel);
                $text($label, 65, $y + 12);
                $text($format($before, $percent), 410, $y + 12);
                $text($format($after, $percent), 685, $y + 12, 23, $teal);
                $delta = $before === null || $after === null ? null : $after - $before;
                $text($delta === null ? 'Not comparable' : ($delta > 0 ? '+' : '').number_format($delta, $percent ? 2 : 0).($percent ? ' pp' : ' votes'), 960, $y + 12, 18, $gold, 260);
            }
            $text('Explore the complete election tables at pollmedia.org', 48, 562, 22, $teal);
        } else {
            imagefilledrectangle($image, 48, 190, 830, 550, $panel);
            $text('VOTER TURNOUT', 70, 222, 14, $muted);
            $text($format($values[$index], true), 70, 268, 32, $teal);
            $text((string) $row['year'], 1080, 225, 32, $gold);
            $text('ELECTION WINNER', 870, 280, 14, $muted);
            $text($row['winner'], 870, 322, 24, $white, 355);
            $text($row['party'], 870, 365, 22, $gold);
            $text('WINNING MARGIN / VOTES', 870, 414, 13, $muted);
            $text($format($row['margin']), 870, 460, 32);
            $maxMargin = max(1, max(array_column($rows, 'margin')));
            imagefilledrectangle($image, 870, 486, 1230, 502, $panel);
            if ($row['margin'] !== null) {
                imagefilledrectangle($image, 870, 486, (int) (870 + 360 * $row['margin'] / $maxMargin), 502, $gold);
            }
            $text('Fixed scale: 0 to '.number_format($maxMargin).' votes', 870, 530, 11, $muted);
            $x = fn ($year) => (int) (105 + 690 * ($year - min($years)) / max(1, max($years) - min($years)));
            $y = fn ($value) => (int) (502 - $value * 1.92);
            foreach ([0, 25, 50, 75, 100] as $tick) {
                imageline($image, 105, $y($tick), 795, $y($tick), $bg);
                $text((string) $tick, 65, $y($tick) + 5, 11, $muted);
            }
            $previous = null;
            imagesetthickness($image, 3);
            for ($i = 0; $i <= $index; $i++) {
                if ($values[$i] === null) {
                    $previous = null;

                    continue;
                }
                $point = [$x($years[$i]), $y($values[$i])];
                if ($previous) {
                    imageline($image, $previous[0], $previous[1], $point[0], $point[1], $teal);
                }
                imagefilledellipse($image, $point[0], $point[1], $i === $index ? 12 : 6, $i === $index ? 12 : 6, $i === $index ? $gold : $teal);
                $previous = $point;
            }
            foreach (array_unique([min($years), $years[(int) floor(count($years) / 2)], max($years)]) as $year) {
                $text((string) $year, $x($year) - 18, 537, 11, $muted);
            }
            $text($row['year'].': '.$format($values[$index], true).' turnout | Winning margin '.$format($row['margin']).' votes', 48, 595, 22);
            if ($row['review']) {
                $text('This year is flagged for review in the source table.', 48, 630, 16, $gold);
            }
        }
        $text('Boundaries may change. Lines join recorded elections; intermediate years are not observations.', 48, 665, 13, $muted);
        $text('Source: pollmedia.org | '.$place.' constituency tables | Review flags retained', 48, 696, 13, $muted);
        imagepng($image, $path);
        imagedestroy($image);
    }
}
