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
            $seconds = max(10, min(25, $count)) / $count;
            $this->frame($package, $count - 1, true, $directory.'/complete.png');
            $list = "file 'complete.png'\nduration 2\n";
            for ($i = 0; $i < $count; $i++) {
                $this->frame($package, $i, false, $directory.'/year-'.$i.'.png');
                $list .= "file 'year-{$i}.png'\nduration ".sprintf('%.6F', $seconds)."\n";
            }
            $list .= "file 'complete.png'\nduration 3\nfile 'complete.png'\n";
            File::put($directory.'/frames.txt', $list);
            $duration = 5 + $count * $seconds;
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
}
