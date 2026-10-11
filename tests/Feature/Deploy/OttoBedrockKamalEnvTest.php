<?php

namespace Tests\Feature\Deploy;

use Tests\TestCase;

class OttoBedrockKamalEnvTest extends TestCase
{
    public function test_deploy_yml_forwards_otto_and_bedrock_env(): void
    {
        $deploy = file_get_contents(base_path('config/deploy.yml'));

        $this->assertNotFalse($deploy);
        $this->assertStringContainsString('OTTO_PROVIDER:', $deploy);
        $this->assertStringContainsString('OTTO_MODEL:', $deploy);
        $this->assertStringContainsString('us.xai.grok-4.6', $deploy);
        $this->assertStringContainsString('OTTO_TOOL_CALL_LIMIT:', $deploy);
        $this->assertStringContainsString('OTTO_LOG_LEVEL:', $deploy);
        $this->assertStringContainsString('OTTO_PRICE_PROMPT_PER_M:', $deploy);
        $this->assertStringContainsString('OTTO_PRICE_COMPLETION_PER_M:', $deploy);
        $this->assertStringContainsString('OTTO_PRICE_CACHE_READ_PER_M:', $deploy);
        $this->assertStringContainsString("'2.20'", $deploy);
        $this->assertStringContainsString("'6.60'", $deploy);
        $this->assertStringContainsString("'0.55'", $deploy);
        $this->assertStringContainsString('AWS_BEDROCK_REGION:', $deploy);
        $this->assertStringContainsString('AWS_BEDROCK_USE_DEFAULT_CREDENTIALS:', $deploy);
        $this->assertStringContainsString('- BEDROCK_KEY', $deploy);
        $this->assertStringContainsString('- BEDROCK_SECRET', $deploy);
        $this->assertStringContainsString('- AWS_BEDROCK_ACCESS_KEY_ID', $deploy);
        $this->assertStringContainsString('- AWS_BEDROCK_SECRET_ACCESS_KEY', $deploy);
    }

    public function test_kamal_secrets_resolve_bedrock_credentials(): void
    {
        $secrets = file_get_contents(base_path('.kamal/secrets'));

        $this->assertNotFalse($secrets);
        $this->assertStringContainsString('BEDROCK_KEY=$BEDROCK_KEY', $secrets);
        $this->assertStringContainsString('BEDROCK_SECRET=$BEDROCK_SECRET', $secrets);
        $this->assertStringContainsString('AWS_BEDROCK_ACCESS_KEY_ID=$AWS_BEDROCK_ACCESS_KEY_ID', $secrets);
        $this->assertStringContainsString('AWS_BEDROCK_SECRET_ACCESS_KEY=$AWS_BEDROCK_SECRET_ACCESS_KEY', $secrets);
    }

    public function test_kamal_workflows_map_otto_and_bedrock_from_github_config(): void
    {
        foreach ([
            '.github/workflows/kamal-pr-preview.yml',
            '.github/workflows/kamal-deploy.yml',
        ] as $relative) {
            $workflow = file_get_contents(base_path($relative));

            $this->assertNotFalse($workflow, $relative.' is unreadable');
            $this->assertStringContainsString('OTTO_PROVIDER: ${{ vars.OTTO_PROVIDER || vars.QA_OTTO_PROVIDER || \'bedrock\' }}', $workflow, $relative);
            $this->assertStringContainsString('OTTO_MODEL: ${{ vars.OTTO_MODEL || vars.QA_OTTO_MODEL }}', $workflow, $relative);
            $this->assertStringContainsString('OTTO_PRICE_PROMPT_PER_M: ${{ vars.OTTO_PRICE_PROMPT_PER_M || vars.QA_OTTO_PRICE_PROMPT_PER_M || \'2.20\' }}', $workflow, $relative);
            $this->assertStringContainsString('OTTO_PRICE_COMPLETION_PER_M: ${{ vars.OTTO_PRICE_COMPLETION_PER_M || vars.QA_OTTO_PRICE_COMPLETION_PER_M || \'6.60\' }}', $workflow, $relative);
            $this->assertStringContainsString('OTTO_PRICE_CACHE_READ_PER_M: ${{ vars.OTTO_PRICE_CACHE_READ_PER_M || vars.QA_OTTO_PRICE_CACHE_READ_PER_M || \'0.55\' }}', $workflow, $relative);
            $this->assertStringContainsString('AWS_BEDROCK_REGION:', $workflow, $relative);
            $this->assertStringContainsString('AWS_BEDROCK_USE_DEFAULT_CREDENTIALS:', $workflow, $relative);
            $this->assertStringContainsString('BEDROCK_KEY: ${{ secrets.BEDROCK_KEY || secrets.AWS_BEDROCK_ACCESS_KEY_ID }}', $workflow, $relative);
            $this->assertStringContainsString('BEDROCK_SECRET: ${{ secrets.BEDROCK_SECRET || secrets.AWS_BEDROCK_SECRET_ACCESS_KEY }}', $workflow, $relative);
            $this->assertStringContainsString('AWS_BEDROCK_ACCESS_KEY_ID: ${{ secrets.AWS_BEDROCK_ACCESS_KEY_ID || secrets.BEDROCK_KEY }}', $workflow, $relative);
            $this->assertStringContainsString('AWS_BEDROCK_SECRET_ACCESS_KEY: ${{ secrets.AWS_BEDROCK_SECRET_ACCESS_KEY || secrets.BEDROCK_SECRET }}', $workflow, $relative);
        }
    }
}
