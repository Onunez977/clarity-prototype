<?php

namespace Tests\Feature\Deploy;

use Tests\TestCase;

class WebsocketKamalEnvTest extends TestCase
{
    public function test_dockerfile_bakes_vite_pusher_args(): void
    {
        $dockerfile = file_get_contents(base_path('Dockerfile'));

        $this->assertNotFalse($dockerfile);
        $this->assertStringContainsString('ARG VITE_PUSHER_APP_KEY=', $dockerfile);
        $this->assertStringContainsString('ARG VITE_PUSHER_APP_CLUSTER=', $dockerfile);
        $this->assertStringContainsString('ARG VITE_PUSHER_HOST=', $dockerfile);
        $this->assertStringContainsString('ARG VITE_PUSHER_PORT=', $dockerfile);
        $this->assertStringContainsString('ARG VITE_PUSHER_SCHEME=', $dockerfile);
        $this->assertStringContainsString('VITE_PUSHER_APP_KEY="${VITE_PUSHER_APP_KEY}"', $dockerfile);
        $this->assertStringContainsString('VITE_PUSHER_HOST="${VITE_PUSHER_HOST}"', $dockerfile);
    }

    public function test_deploy_yml_forwards_websocket_builder_args_and_runtime_env(): void
    {
        $deploy = file_get_contents(base_path('config/deploy.yml'));

        $this->assertNotFalse($deploy);
        $this->assertStringContainsString('VITE_PUSHER_APP_KEY:', $deploy);
        $this->assertStringContainsString('VITE_PUSHER_APP_CLUSTER:', $deploy);
        $this->assertStringContainsString('VITE_PUSHER_HOST:', $deploy);
        $this->assertStringContainsString('VITE_PUSHER_PORT:', $deploy);
        $this->assertStringContainsString('VITE_PUSHER_SCHEME:', $deploy);
        $this->assertStringContainsString('PUSHER_HOST:', $deploy);
        $this->assertStringContainsString('PUSHER_PORT:', $deploy);
        $this->assertStringContainsString('PUSHER_SCHEME:', $deploy);
        $this->assertStringContainsString('PUSHER_APP_CLUSTER:', $deploy);
        $this->assertStringContainsString('wss.insightlpr.io', $deploy);
        $this->assertStringNotContainsString('app.insightlpr.io', $deploy);
    }

    public function test_preview_workflow_reads_websocket_settings_from_github_vars(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/kamal-pr-preview.yml'));

        $this->assertNotFalse($workflow);
        $this->assertStringNotContainsString('inputs.broadcast_connection', $workflow);
        $this->assertStringNotContainsString('inputs.pusher_host', $workflow);
        $this->assertStringNotContainsString('inputs.vite_pusher_host', $workflow);
        $this->assertStringContainsString(
            'BROADCAST_CONNECTION: ${{ vars.QA_BROADCAST_CONNECTION || \'log\' }}',
            $workflow
        );
        $this->assertStringContainsString(
            'PUSHER_HOST: ${{ vars.QA_PUSHER_HOST || \'wss.insightlpr.io\' }}',
            $workflow
        );
        $this->assertStringContainsString(
            'VITE_PUSHER_HOST: ${{ vars.QA_VITE_PUSHER_HOST || \'wss.insightlpr.io\' }}',
            $workflow
        );
        $this->assertStringContainsString(
            'VITE_PUSHER_APP_KEY: ${{ vars.VITE_PUSHER_APP_KEY || secrets.PUSHER_APP_KEY }}',
            $workflow
        );
    }

    public function test_kamal_workflows_map_websocket_env_from_github_config(): void
    {
        foreach ([
            '.github/workflows/kamal-pr-preview.yml',
            '.github/workflows/kamal-deploy.yml',
        ] as $relative) {
            $workflow = file_get_contents(base_path($relative));

            $this->assertNotFalse($workflow, $relative.' is unreadable');
            $this->assertStringContainsString('vars.QA_PUSHER_HOST', $workflow, $relative);
            $this->assertStringContainsString('vars.QA_VITE_PUSHER_HOST', $workflow, $relative);
            $this->assertStringContainsString(
                'VITE_PUSHER_APP_KEY: ${{ vars.VITE_PUSHER_APP_KEY || secrets.PUSHER_APP_KEY }}',
                $workflow,
                $relative
            );
            $this->assertStringContainsString('PUSHER_APP_ID: ${{ secrets.PUSHER_APP_ID }}', $workflow, $relative);
            $this->assertStringContainsString('PUSHER_APP_KEY: ${{ secrets.PUSHER_APP_KEY }}', $workflow, $relative);
            $this->assertStringContainsString('PUSHER_APP_SECRET: ${{ secrets.PUSHER_APP_SECRET }}', $workflow, $relative);
        }
    }
}
