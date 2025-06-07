<?php

namespace Tests\AI;

use PHPUnit\Framework\TestCase;
use RedmineSummarizer\AI\GeminiClient;

class GeminiClientTest extends TestCase
{
    private $geminiClient;

    protected function setUp(): void
    {
        // テスト用のAPIキー
        $this->geminiClient = new GeminiClient('test_api_key');

        // formatActivitiesForPromptメソッドをテスト用に公開
        $reflectionClass = new \ReflectionClass(GeminiClient::class);
        $method = $reflectionClass->getMethod('formatActivitiesForPrompt');
        $method->setAccessible(true);
        $this->formatMethod = $method;
    }

    public function testFormatActivitiesForPrompt(): void
    {
        $activities = [
            [
                'activity_type' => 'issue_add',
                'project_name' => 'テストプロジェクト',
                'issue_id' => '123',
                'issue_subject' => 'テスト課題',
                'author' => 'user1',
                'created_at' => '2023-01-01 10:00:00',
                'issue_description' => 'これはテスト課題です',
                'comment' => ''
            ],
            [
                'activity_type' => 'issue_edit',
                'project_name' => 'テストプロジェクト',
                'issue_id' => '123',
                'issue_subject' => 'テスト課題',
                'author' => 'user2',
                'created_at' => '2023-01-02 11:00:00',
                'issue_description' => 'これはテスト課題です',
                'comment' => 'コメントを追加しました'
            ]
        ];

        $result = $this->formatMethod->invoke($this->geminiClient, $activities);

        $this->assertStringContainsString('タイプ: issue_add', $result);
        $this->assertStringContainsString('課題ID: 123', $result);
        $this->assertStringContainsString('コメント: コメントを追加しました', $result);
        $this->assertStringContainsString('テストプロジェクト', $result);
    }
}
