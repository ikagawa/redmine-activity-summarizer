<?php

namespace Tests\Redmine;

use PHPUnit\Framework\TestCase;
use RedmineSummarizer\Redmine\RedmineClient;
use Redmine\Client as BaseClient;

class RedmineClientTest extends TestCase
{
    public function testCreateIssue(): void
    {
        // モックの作成
        $baseClientMock = $this->createMock(BaseClient::class);
        $issueMock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['create'])
            ->getMock();

        // issue->createメソッドの期待値を設定
        $issueMock->expects($this->once())
            ->method('create')
            ->with([
                'project_id' => 1,
                'subject' => 'テスト課題',
                'description' => 'テスト説明',
                'tracker_id' => 1
            ])
            ->willReturn((object)[
                'getContent' => function() {
                    return ['issue' => ['id' => 123]];
                }
            ]);

        // BaseClientのプロパティをモックに設定
        $baseClientMock->issue = $issueMock;

        // RedmineClientのインスタンスを作成
        $reflectionClass = new \ReflectionClass(RedmineClient::class);
        $client = $reflectionClass->newInstanceWithoutConstructor();

        // プライベートプロパティにモックを設定
        $reflectionProperty = $reflectionClass->getProperty('client');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($client, $baseClientMock);

        // テスト実行
        $result = $client->createIssue(1, 'テスト課題', 'テスト説明');

        // 検証
        $this->assertEquals(['issue' => ['id' => 123]], $result);
    }
}
