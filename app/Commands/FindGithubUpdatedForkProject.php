<?php

namespace App\Commands;

use Cache;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use LaravelZero\Framework\Commands\Command;

class FindGithubUpdatedForkProject extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'github:find';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'find updated fork project from github';

    /**
     * @var \Illuminate\Http\Client\PendingRequest
     */
    private $http;

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->http = Http::withHeaders([]);

        $uri = Str::of($this->ask('Input github project uri [https://github.com/:owner/:repo]'));
        $onlyUpdated = $this->confirm('Only show updated?', true);
        $tokenFromCache = Cache::get('token', false);
        $token = $this->ask(
            sprintf('Github oauth token %s', ($tokenFromCache ? '[already set]' : '[https://github.com/settings/tokens]'))
        );

        if ($token || $tokenFromCache) {
            $token = $token ?? $tokenFromCache;
            Cache::forever('token', $token);
            $this->http = $this->http->withHeaders(['Authorization' => sprintf('token %s', $token)]);
        }

        if (! $uri->startsWith('https://github.com/')) {
            $this->error('Input uri format error');

            return;
        }

        //https://api.github.com/repos/:owner/:repo/commits?per_page=1
        $uri = $uri->replace('https://github.com/', 'https://api.github.com/repos/');
        $masterCommitCount = $this->getRepositoryCommitCount($uri);
        $this->showRepositoryInfo($uri, $masterCommitCount);

        //https://api.github.com/repos/:owner/:repo/forks
        $forks = $this->http->get($uri->append('/forks'));

        if ($forks->ok()) {
            foreach ($forks->json() as $fork) {
                $forkUri = Str::of($fork['url']);
                $forkCommitCount = $this->getRepositoryCommitCount($forkUri);

                if ($onlyUpdated && $forkCommitCount <= $masterCommitCount) {
                    continue;
                }

                $this->showRepositoryInfo($forkUri, $forkCommitCount);
            }
        }
    }

    /**
     * Define the command's schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }

    /**
     * @param  Stringable  $uri  https://github.com/:owner/:repo
     *
     * @return integer|null
     */
    protected function getRepositoryCommitCount(Stringable $uri): int
    {
        $uri = $uri->append('/commits?per_page=1');

        $response = $this->http->get($uri);

        if (! $response->ok()) {
            $repository = $uri->after('https://api.github.com/repos/')->before('/commits?per_page=1');
            $this->error(sprintf('Can\'t access project [%s]', $repository));

            $this->info(json_encode($response->json()));

            return 0;
        }

        $link = $response->header('Link');
        preg_match('/next.*page=(.*)>/', $link, $match);

        return $match[1];
    }

    protected function showRepositoryInfo(Stringable $uri, ?int $repositoryCommitCount)
    {
        $repository = $uri->after('https://api.github.com/repos/');
        $repositoryUri = "https://github.com/$repository";
        $this->info(sprintf('[%s] commit count : %s (%s)', $repository, $repositoryCommitCount, $repositoryUri));
    }
}
