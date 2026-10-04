<?php

namespace App\Console\Commands;

use App\Services\Research\ChartVideo;
use App\Services\Research\FetchSource;
use App\Services\Research\PollmediaChart;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('hub:preview-chart-video {url} {--graph=turnout} {--html= : Optional saved source HTML for an offline rendering test}')]
#[Description('Render one historical chart video without scheduling or publishing a post')]
class PreviewChartVideo extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PollmediaChart $charts, ChartVideo $video, FetchSource $reader): int
    {
        $url = $this->argument('url');
        $index = array_search($this->option('graph'), PollmediaChart::GRAPHS, true);
        if ($index === false || ! in_array(parse_url($url, PHP_URL_HOST), ['pollmedia.org', 'www.pollmedia.org'], true)) {
            $this->error('Use a Pollmedia page and graph: '.implode(', ', PollmediaChart::GRAPHS));

            return self::FAILURE;
        }
        try {
            $html = $this->option('html') ? file_get_contents($this->option('html')) : $reader->publicHtml($url);
            $selected = $charts->select($html, $url, $index);
            if (! $selected) {
                throw new \RuntimeException('No graph has at least two usable historical observations.');
            }
            parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
            $place = $query['name'] ?? ucwords(str_replace('-', ' ', basename(parse_url($url, PHP_URL_PATH))));
            $media = $video->create(['title' => $place.' | '.$selected['visual']['heading'], 'source_url' => $url, 'visual' => $selected['visual']]);
            $this->info('Rendered '.$selected['visual']['graph_key'].'. No post was scheduled or published.');
            $this->line(Storage::disk('local')->path($media['video_path']));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error instanceof \RuntimeException ? $error->getMessage() : 'Chart preview failed: '.class_basename($error));

            return self::FAILURE;
        }
    }
}
