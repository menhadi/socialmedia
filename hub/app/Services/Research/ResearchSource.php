<?php

namespace App\Services\Research;

use App\Models\ContentSource;
use App\Models\SourceSnapshot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ResearchSource
{
    public function __construct(private FetchSource $reader, private CreateSourceDraft $drafts) {}

    public function run(ContentSource $source): ?SourceSnapshot
    {
        $lock = Cache::lock('source-research-'.$source->id, 180);
        if (! $lock->get()) {
            return null;
        }
        try {
            $source->refresh();
            $version = $source->version;
            $baseline = $source->last_hash === null;
            $text = $this->reader->fetch($source->url, $source->element_id);
            $comparison = $source->comparison_url ? $this->reader->fetch($source->comparison_url) : null;
            $hash = hash('sha256', $text."\n".($comparison ?? ''));
            $snapshot = DB::transaction(function () use ($source, $version, $text, $comparison, $hash): ?SourceSnapshot {
                $locked = ContentSource::whereKey($source->id)->lockForUpdate()->firstOrFail();
                if ($locked->version !== $version) {
                    return null;
                }
                $locked->update(['checked_at' => now(), 'next_check_at' => now()->addMinutes($locked->interval_minutes), 'last_hash' => $hash, 'last_error' => null]);

                return $locked->snapshots()->firstOrCreate(['source_version' => $version, 'hash' => $hash], [
                    'url' => $source->url, 'comparison_url' => $source->comparison_url, 'text' => $text,
                    'comparison_text' => $comparison, 'checked_at' => now(),
                ]);
            });
            if ($snapshot && $snapshot->wasRecentlyCreated) {
                $this->drafts->run($snapshot, $baseline);
            }

            return $snapshot;
        } catch (\Throwable $e) {
            ContentSource::whereKey($source->id)->where('version', $source->version)->update([
                'last_error' => 'The source check could not finish. Check the final public URL, content element ID and supported format (readable HTML, feed or PDF up to 4 MB).',
                'next_check_at' => now()->addMinutes($source->interval_minutes),
            ]);

            return null;
        } finally {
            $lock->release();
        }
    }
}
