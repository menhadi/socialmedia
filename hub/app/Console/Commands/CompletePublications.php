<?php

namespace App\Console\Commands;

use App\Models\Publication;
use App\Services\Social\PublishPost;
use Illuminate\Console\Command;

class CompletePublications extends Command
{
    protected $signature = 'hub:complete-publications';

    protected $description = 'Check uploaded media and complete pending publications without resending ambiguous submissions';

    public function handle(PublishPost $publisher): int
    {
        $started = microtime(true);
        foreach (Publication::where('status', 'publishing')->where('next_check_at', '<=', now())->orderBy('next_check_at')->limit(10)->get() as $publication) {
            $publisher->resume($publication);
            if (microtime(true) - $started > 45) {
                break;
            }
        }

        return self::SUCCESS;
    }
}
