<?php

namespace InsightLPR\BlackAdam\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class Deploy extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:deploy';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        return;
        $tag = exec('git --git-dir ' . base_path('.git') . ' tag --sort=committerdate');
        $hash = trim(exec('git --git-dir ' . base_path('.git') . ' log --pretty="%h" -n1 HEAD'));

        $name = Str::lower(Config::get('app.name'));


    }
}
