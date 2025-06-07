<?php

namespace Tests\Redmine;

use PHPUnit\Framework\TestCase;
use RedmineSummarizer\Redmine\RedmineClient;

class RedmineClientTest extends TestCase
{
    public function testCreateIssue(): void
    {
        // モック化されたメソッドが期待通りの値を返すパーシャルモックを作成
        $client = $this->getMockBuilder(RedmineClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['makeRequest'])
            ->getMock();

        // makeRequestメソッドが期待通りのパラメータで呼び出され、適切な結果を返すことを設定
        $client->expects($this->once())
            ->method('makeRequest')
            ->with(
                'POST',
                '/issues.json', 
                [
                    'issue' => [
                        'project_id' => 1,
                        'subject' => 'テスト課題',
                        'description' => 'テスト説明',
                        'tracker_id' => 1
                    ]
                ]
            )
            ->willReturn(['issue' => ['id' => 123]]);

        // テスト実行
        $result = $client->createIssue(1, 'テスト課題', 'テスト説明');

        // 検証
        $this->assertEquals(['issue' => ['id' => 123]], $result);
    }
}
