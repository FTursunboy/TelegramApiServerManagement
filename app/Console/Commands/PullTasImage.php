<?php

namespace App\Console\Commands;

use App\Services\DockerService;
use Illuminate\Console\Command;

class PullTasImage extends Command
{
    protected $signature = 'tas:pull-image';
    protected $description = 'Pull TelegramApiServer Docker image';

    public function handle(DockerService $docker): int
    {
        $image = config('tas.docker.image');
        
        $this->info("Pulling image: {$image}");
        $this->info('This may take a few minutes...');

        if ($docker->pullImage($image)) {
            $this->info('✓ Image pulled successfully');
            return 0;
        }

        $this->error('✗ Failed to pull image');
        return 1;
    }
}

