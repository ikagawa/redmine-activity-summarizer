<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use RedmineSummarizer\SummarizerService;
use RedmineSummarizer\Database\RedmineDatabase;
use RedmineSummarizer\AI\GeminiClient;
use RedmineSummarizer\Redmine\RedmineClient;

class SummarizerServiceTest extends TestCase
{
    private $database;
    private $gemini;
    private $redmine;
    private $summarizer;

    protected function setUp(): void
    {
        // モックオブジェクトを作成
        $this->database = $this->createMock(RedmineDatabase::class);
        $this->gemini = $this->createMock(GeminiClient::class);
        $this->redmine = $this->createMock(RedmineClient::class);

        // テスト対象のSummarizerServiceを作成
        $this->summarizer = new SummarizerService(
            $this->database,
            $this->gemini,
            $this->redmine,
            7, // activityDays
            1, // projectId
            false, // debug
            true // includeTokenInfo
        );

        // メソッドをリフレクションで公開
        $reflectionClass = new \ReflectionClass(SummarizerService::class);
        $this->runWithDateRangeMethod = $reflectionClass->getMethod('runWithDateRange');
        $this->runWithDateRangeMethod->setAccessible(true);

        $this->runForProjectWithDateRangeMethod = $reflectionClass->getMethod('runForProjectWithDateRange');
        $this->runForProjectWithDateRangeMethod->setAccessible(true);

        $this->runMethod = $reflectionClass->getMethod('run');
        $this->runMethod->setAccessible(true);

        $this->runForProjectMethod = $reflectionClass->getMethod('runForProject');
        $this->runForProjectMethod->setAccessible(true);

        // 出力をバッファリングして抑制
        ob_start();
    }

    public function testWikiTitlePrefixInRunWithDateRange(): void
    {
        // SummarizerServiceのサブクラスを作成してファイル操作をオーバーライド
        $summarizer = new class(
            $this->database,
            $this->gemini,
            $this->redmine,
            7, // activityDays
            1, // projectId
            false, // debug
            true // includeTokenInfo
        ) extends SummarizerService {
            protected function saveSummaryToTempFile(string $summary, string $filename): string
            {
                return '/mock/path/to/temp/file.md';
            }

            protected function deleteTempFile(string $filepath): void
            {
                // 何もしない
            }
        };

        // モックの設定
        $this->database->expects($this->once())
            ->method('getActivitiesByDateRange')
            ->with('2025-01-01', '2025-01-31')
            ->willReturn([['activity_type' => 'issue_add', 'project_name' => 'Test']]);

        $this->gemini->expects($this->once())
            ->method('summarizeActivities')
            ->willReturn('テスト要約');

        // RedmineClientのupdateWikiPageが正しいタイトルで呼ばれることを検証
        $this->redmine->expects($this->once())
            ->method('updateWikiPage')
            ->with(
                1, // projectId
                'CustomTitle_2025-01-01_to_2025-01-31', // 重要: ここでカスタムタイトルが設定されているか確認
                'テスト要約',
                '2025-01-01から2025-01-31までのアクティビティ要約を自動生成'
            );

        // パブリックメソッドとして直接呼び出し
        $summarizer->runWithDateRange('2025-01-01', '2025-01-31', null, 'CustomTitle');
    }

    public function testWikiTitlePrefixInRunForProjectWithDateRange(): void
    {
        // SummarizerServiceのサブクラスを作成してファイル操作をオーバーライド
        $summarizer = new class(
            $this->database,
            $this->gemini,
            $this->redmine,
            7, // activityDays
            1, // projectId
            false, // debug
            true // includeTokenInfo
        ) extends SummarizerService {
            protected function saveSummaryToTempFile(string $summary, string $filename): string
            {
                return '/mock/path/to/temp/file.md';
            }

            protected function deleteTempFile(string $filepath): void
            {
                // 何もしない
            }
        };

        // モックの設定
        $this->database->expects($this->once())
            ->method('getProjectActivitiesByDateRange')
            ->with(5, '2025-01-01', '2025-01-31')
            ->willReturn([['activity_type' => 'issue_add', 'project_name' => 'Test']]);

        $this->gemini->expects($this->once())
            ->method('summarizeActivities')
            ->willReturn('テスト要約');

        // RedmineClientのupdateWikiPageが正しいタイトルで呼ばれることを検証
        $this->redmine->expects($this->once())
            ->method('updateWikiPage')
            ->with(
                5, // projectId
                'MayReport_2025-01-01_to_2025-01-31', // 重要: ここでカスタムタイトルが設定されているか確認
                'テスト要約',
                'プロジェクト5の2025-01-01から2025-01-31までのアクティビティ要約を自動生成'
            );

        // パブリックメソッドとして直接呼び出し
        $summarizer->runForProjectWithDateRange(5, '2025-01-01', '2025-01-31', null, 'MayReport');
    }

    public function testWikiTitlePrefixInRun(): void
    {
        // SummarizerServiceのサブクラスを作成してファイル操作をオーバーライド
        $summarizer = new class(
            $this->database,
            $this->gemini,
            $this->redmine,
            7, // activityDays
            1, // projectId
            false, // debug
            true // includeTokenInfo
        ) extends SummarizerService {
            protected function saveSummaryToTempFile(string $summary, string $filename): string
            {
                return '/mock/path/to/temp/file.md';
            }

            protected function deleteTempFile(string $filepath): void
            {
                // 何もしない
            }
        };

        // モックの設定
        $this->database->expects($this->once())
            ->method('getActivities')
            ->willReturn([['activity_type' => 'issue_add', 'project_name' => 'Test']]);

        $this->gemini->expects($this->once())
            ->method('summarizeActivities')
            ->willReturn('テスト要約');

        // RedmineClientのupdateWikiPageが正しいタイトルで呼ばれることを検証
        // 現在の実際の日付で検証するように変更
        $currentDate = date('Y-m-d');
        $this->redmine->expects($this->once())
            ->method('updateWikiPage')
            ->with(
                1, // projectId
                'WeeklyReport_' . $currentDate, // 現在の日付を使用
                'テスト要約',
                '過去7日間のアクティビティ要約を自動生成'
            );

        // パブリックメソッドとして直接呼び出し
        $summarizer->run(null, 'WeeklyReport');
    }

    public function testWikiTitlePrefixInRunForProject(): void
    {
        // SummarizerServiceのサブクラスを作成してファイル操作をオーバーライド
        $summarizer = new class(
            $this->database,
            $this->gemini,
            $this->redmine,
            7, // activityDays
            1, // projectId
            false, // debug
            true // includeTokenInfo
        ) extends SummarizerService {
            protected function saveSummaryToTempFile(string $summary, string $filename): string
            {
                return '/mock/path/to/temp/file.md';
            }

            protected function deleteTempFile(string $filepath): void
            {
                // 何もしない
            }
        };

        // モックの設定
        $this->database->expects($this->once())
            ->method('getProjectActivities')
            ->with(5, 7)
            ->willReturn([['activity_type' => 'issue_add', 'project_name' => 'Test']]);

        $this->gemini->expects($this->once())
            ->method('summarizeActivities')
            ->willReturn('テスト要約');

        // RedmineClientのupdateWikiPageが正しいタイトルで呼ばれることを検証
        // 現在の実際の日付で検証するように変更
        $currentDate = date('Y-m-d');
        $this->redmine->expects($this->once())
            ->method('updateWikiPage')
            ->with(
                5, // projectId
                'TeamReport_' . $currentDate, // 現在の日付を使用
                'テスト要約',
                'プロジェクト5の過去7日間のアクティビティ要約を自動生成'
            );

        // パブリックメソッドとして直接呼び出し
        $summarizer->runForProject(5, null, 'TeamReport');
    }

    protected function tearDown(): void
    {
        // 出力バッファをクリア
        ob_end_clean();
    }
}
