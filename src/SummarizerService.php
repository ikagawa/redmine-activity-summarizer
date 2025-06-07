<?php

namespace RedmineSummarizer;

use RedmineSummarizer\Database\RedmineDatabase;
use RedmineSummarizer\AI\GeminiClient;
use RedmineSummarizer\Redmine\RedmineClient;

class SummarizerService
{
    private RedmineDatabase $database;
    private GeminiClient $gemini;
    private RedmineClient $redmine;
    private int $activityDays;
    private int $projectId;
    private string $tempDir;
    private bool $debug;
    private bool $includeTokenInfo;

    public function __construct(
        RedmineDatabase $database,
        GeminiClient $gemini,
        RedmineClient $redmine,
        int $activityDays,
        int $projectId,
        bool $debug = false,
        bool $includeTokenInfo = true
    ) {
        $this->database = $database;
        $this->gemini = $gemini;
        $this->redmine = $redmine;
        $this->activityDays = $activityDays;
        $this->projectId = $projectId;
        $this->debug = $debug;
        $this->includeTokenInfo = $includeTokenInfo;
        
        // デバッグモードをRedmineClientに設定
        $this->redmine->setDebug($debug);
        
        // 一時ファイル保存ディレクトリを設定
        $this->tempDir = __DIR__ . '/../temp';
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }

    /**
     * Redmine URL診断
     */
    public function diagnoseRedmineUrl(): void
    {
        echo "Redmine URL診断を実行中...\n";
        $results = $this->redmine->testUrlAccess();
        
        foreach ($results as $key => $result) {
            echo "\n--- {$key} ---\n";
            echo "URL: {$result['url']}\n";
            echo "Status Code: {$result['status_code']}\n";
            echo "成功: " . ($result['success'] ? 'Yes' : 'No') . "\n";
            echo "応答時間: {$result['total_time']}秒\n";
            
            if (!empty($result['curl_error'])) {
                echo "cURL Error: {$result['curl_error']}\n";
            }
            
            if ($result['success']) {
                echo "✓ この URL は正常にアクセスできます\n";
            }
        }
        
        // 推奨事項の表示
        echo "\n=== 推奨事項 ===\n";
        $hasSuccess = false;
        foreach ($results as $result) {
            if ($result['success']) {
                echo "推奨URL: {$result['url']}\n";
                $hasSuccess = true;
                break;
            }
        }
        
        if (!$hasSuccess) {
            echo "すべてのURLでエラーが発生しています。\n";
            echo "1. .envファイルのREDMINE_URLを確認してください\n";
            echo "2. Redmineサーバーが稼働していることを確認してください\n";
            echo "3. ネットワーク接続を確認してください\n";
            echo "4. --insecure オプションでSSL検証を無効にして試してください\n";
        }
    }

    /**
     * Redmine API接続テスト
     */
    public function testRedmineConnection(): void
    {
        echo "Redmine API接続テストを実行中...\n";
        $result = $this->redmine->testConnection();
        
        if ($result['success']) {
            echo "✓ {$result['message']}\n";
            if ($this->debug && $result['data']) {
                echo "取得データ: " . json_encode($result['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            }
        } else {
            echo "✗ {$result['message']}\n";
            throw new \Exception("Redmine API接続に失敗しました");
        }
    }

    /**
     * 期間を指定してアクティビティ要約を実行
     *
     * @param string $fromDate 開始日（YYYY-MM-DD形式）
     * @param string $toDate 終了日（YYYY-MM-DD形式）
     * @param string|null $customPrompt カスタムプロンプト（nullの場合はデフォルト）
     * @param string|null $wikiTitlePrefix Wikiページタイトルのプレフィックス（nullの場合は「ActivitySummary」）
     */
    public function runWithDateRange(string $fromDate, string $toDate, ?string $customPrompt = null, ?string $wikiTitlePrefix = null): void
    {
        $tempFile = null;

        try {
            // API接続テスト
            if ($this->debug) {
                $this->testRedmineConnection();
            }

            // 1. PostgreSQLから指定期間のアクティビティを取得
            echo "期間指定（{$fromDate} から {$toDate}）でアクティビティデータを取得中...\n";
            $activities = $this->database->getActivitiesByDateRange($fromDate, $toDate);

            if (empty($activities)) {
                echo "{$fromDate}から{$toDate}までのアクティビティはありません。\n";
                return;
            }

            echo count($activities) . "件のアクティビティを取得しました。\n";

            // 2. Gemini 2.5で要約を生成
            echo "アクティビティの要約を生成中...\n";
            $summary = $this->gemini->summarizeActivities($activities, $customPrompt, $this->includeTokenInfo);

            // 3. 要約を一時ファイルに保存
            $date = date('Y-m-d_H-i-s');
            $tempFile = $this->saveSummaryToTempFile($summary, "activities_{$fromDate}_to_{$toDate}_{$date}", $fromDate, $toDate);
            echo "要約を一時ファイルに保存しました: {$tempFile}\n";

            // 4. 要約をRedmineのWikiページに投稿
            echo "生成された要約をRedmine Wikiに投稿中...\n";

            // Wikiページとして投稿
            $prefix = $wikiTitlePrefix ?? 'ActivitySummary';
            $title = "{$prefix}_{$fromDate}_to_{$toDate}";

            if ($this->debug) {
                echo "プロジェクト情報を取得中...\n";
            }
            $project = $this->redmine->getProject($this->projectId);

            $this->redmine->updateWikiPage(
                $this->projectId,
                $title,
                $summary,
                "{$fromDate}から{$toDate}までのアクティビティ要約を自動生成"
            );

            echo "要約が正常にWikiページに投稿されました。Wiki: {$title}\n";

            // 5. 投稿成功時に一時ファイルを削除
            $this->deleteTempFile($tempFile);
            echo "一時ファイルを削除しました。\n";

        } catch (\Exception $e) {
            echo "エラーが発生しました: {$e->getMessage()}\n";
            if ($tempFile) {
                echo "要約は一時ファイルに保存されています: {$tempFile}\n";
            }
            exit(1);
        }
    }

    /**
     * 特定プロジェクトの期間指定要約を実行
     * 
     * @param int $projectId プロジェクトID
     * @param string $fromDate 開始日（YYYY-MM-DD形式）
     * @param string $toDate 終了日（YYYY-MM-DD形式）
     * @param string|null $customPrompt カスタムプロンプト（nullの場合はデフォルト）
     * @param string|null $wikiTitlePrefix Wikiページタイトルのプレフィックス（nullの場合は「Project{ID}_ActivitySummary」）
     */
    public function runForProjectWithDateRange(int $projectId, string $fromDate, string $toDate, ?string $customPrompt = null, ?string $wikiTitlePrefix = null): void
    {
        $tempFile = null;

        try {
            // API接続テスト
            if ($this->debug) {
                $this->testRedmineConnection();
            }

            // 1. PostgreSQLから指定期間の特定プロジェクトのアクティビティを取得
            echo "プロジェクトID {$projectId} の期間指定（{$fromDate} から {$toDate}）でアクティビティを取得中...\n";
            $activities = $this->database->getProjectActivitiesByDateRange($projectId, $fromDate, $toDate);

            if (empty($activities)) {
                echo "プロジェクトID {$projectId} の{$fromDate}から{$toDate}までのアクティビティはありません。\n";
                return;
            }

            echo count($activities) . "件のアクティビティを取得しました。\n";

            // 2. Gemini 2.5で要約を生成
            echo "アクティビティの要約を生成中...\n";
            $summary = $this->gemini->summarizeActivities($activities, $customPrompt, $this->includeTokenInfo);

            // 3. 要約を一時ファイルに保存
            $date = date('Y-m-d_H-i-s');
            $tempFile = $this->saveSummaryToTempFile($summary, "project_{$projectId}_{$fromDate}_to_{$toDate}_{$date}", $fromDate, $toDate);
            echo "要約を一時ファイルに保存しました: {$tempFile}\n";

            // 4. 要約をRedmineのWikiページに投稿（課題ではなくWikiに変更）
            echo "生成された要約をRedmine Wikiに投稿中...\n";

            // Wikiページとして投稿
            $prefix = $wikiTitlePrefix ?? "Project{$projectId}_ActivitySummary";
            $title = "{$prefix}_{$fromDate}_to_{$toDate}";

            if ($this->debug) {
                echo "プロジェクト情報を取得中...\n";
            }
            $project = $this->redmine->getProject($projectId);

            $this->redmine->updateWikiPage(
                $projectId,
                $title,
                $summary,
                "プロジェクト{$projectId}の{$fromDate}から{$toDate}までのアクティビティ要約を自動生成"
            );

            echo "要約が正常にWikiページに投稿されました。Wiki: {$title}\n";

            // 5. 投稿成功時に一時ファイルを削除
            $this->deleteTempFile($tempFile);
            echo "一時ファイルを削除しました。\n";

        } catch (\Exception $e) {
            echo "エラーが発生しました: {$e->getMessage()}\n";
            if ($tempFile) {
                echo "要約は一時ファイルに保存されています: {$tempFile}\n";
            }
            exit(1);
        }
    }

    /**
     * アクティビティ要約のメイン処理を実行
     *
     * @param string|null $customPrompt カスタムプロンプト（nullの場合はデフォルト）
     * @param string|null $wikiTitlePrefix Wikiページタイトルのプレフィックス（nullの場合は「ActivitySummary」）
     */
    public function run(?string $customPrompt = null, ?string $wikiTitlePrefix = null): void
    {
        $tempFile = null;
        
        try {
            // API接続テスト
            if ($this->debug) {
                $this->testRedmineConnection();
            }

            // 1. PostgreSQLからアクティビティを取得
            echo "アクティビティデータを取得中...\n";
            $activities = $this->database->getActivities($this->activityDays);

            if (empty($activities)) {
                echo "過去{$this->activityDays}日間のアクティビティはありません。\n";
                return;
            }

            echo count($activities) . "件のアクティビティを取得しました。\n";

            // 2. Gemini 2.5で要約を生成
            echo "アクティビティの要約を生成中...\n";
            $summary = $this->gemini->summarizeActivities($activities, $customPrompt, $this->includeTokenInfo);

            // 3. 要約を一時ファイルに保存
            $date = date('Y-m-d_H-i-s');
            $tempFile = $this->saveSummaryToTempFile($summary, "all_activities_{$date}");
            echo "要約を一時ファイルに保存しました: {$tempFile}\n";

            // 4. 要約をRedmineのWikiページに投稿
            echo "生成された要約をRedmine Wikiに投稿中...\n";

            // Wikiページとして投稿
            $wikiDate = date('Y-m-d');
            $prefix = $wikiTitlePrefix ?? 'ActivitySummary';
            $title = "{$prefix}_{$wikiDate}";
            
            if ($this->debug) {
                echo "プロジェクト情報を取得中...\n";
            }
            $project = $this->redmine->getProject($this->projectId);

            $this->redmine->updateWikiPage(
                $this->projectId,
                $title,
                $summary,
                "過去{$this->activityDays}日間のアクティビティ要約を自動生成"
            );

            echo "要約が正常にWikiページに投稿されました。Wiki: {$title}\n";

            // 5. 投稿成功時に一時ファイルを削除
            $this->deleteTempFile($tempFile);
            echo "一時ファイルを削除しました。\n";

        } catch (\Exception $e) {
            echo "エラーが発生しました: {$e->getMessage()}\n";
            if ($tempFile) {
                echo "要約は一時ファイルに保存されています: {$tempFile}\n";
            }
            exit(1);
        }
    }

    /**
     * 特定のプロジェクトのアクティビティのみ要約
     * 
     * @param int $targetProjectId 対象プロジェクトID
     * @param string|null $customPrompt カスタムプロンプト（nullの場合はデフォルト）
     * @param string|null $wikiTitlePrefix Wikiページタイトルのプレフィックス（nullの場合は「Project{ID}_ActivitySummary」）
     */
    public function runForProject(int $targetProjectId, ?string $customPrompt = null, ?string $wikiTitlePrefix = null): void
    {
        $tempFile = null;
        
        try {
            // API接続テスト
            if ($this->debug) {
                $this->testRedmineConnection();
            }

            // 1. PostgreSQLから特定プロジェクトのアクティビティを取得
            echo "プロジェクトID {$targetProjectId} のアクティビティを取得中...\n";
            $activities = $this->database->getProjectActivities($targetProjectId, $this->activityDays);

            if (empty($activities)) {
                echo "プロジェクトID {$targetProjectId} の過去{$this->activityDays}日間のアクティビティはありません。\n";
                return;
            }

            echo count($activities) . "件のアクティビティを取得しました。\n";

            // 2. Gemini 2.5で要約を生成
            echo "アクティビティの要約を生成中...\n";
            $summary = $this->gemini->summarizeActivities($activities, $customPrompt, $this->includeTokenInfo);

            // 3. 要約を一時ファイルに保存
            $date = date('Y-m-d_H-i-s');
            $tempFile = $this->saveSummaryToTempFile($summary, "project_{$targetProjectId}_{$date}");
            echo "要約を一時ファイルに保存しました: {$tempFile}\n";

            // 4. 要約をRedmineのWikiページに投稿（課題ではなくWikiに変更）
            echo "生成された要約をRedmine Wikiに投稿中...\n";

            // Wikiページとして投稿
            $wikiDate = date('Y-m-d');
            $prefix = $wikiTitlePrefix ?? "Project{$targetProjectId}_ActivitySummary";
            $title = "{$prefix}_{$wikiDate}";
            
            if ($this->debug) {
                echo "プロジェクト情報を取得中...\n";
            }
            $project = $this->redmine->getProject($targetProjectId);

            $this->redmine->updateWikiPage(
                $targetProjectId,
                $title,
                $summary,
                "プロジェクト{$targetProjectId}の過去{$this->activityDays}日間のアクティビティ要約を自動生成"
            );

            echo "要約が正常にWikiページに投稿されました。Wiki: {$title}\n";

            // 5. 投稿成功時に一時ファイルを削除
            $this->deleteTempFile($tempFile);
            echo "一時ファイルを削除しました。\n";

        } catch (\Exception $e) {
            echo "エラーが発生しました: {$e->getMessage()}\n";
            if ($tempFile) {
                echo "要約は一時ファイルに保存されています: {$tempFile}\n";
            }
            exit(1);
        }
    }

    protected function saveSummaryToTempFile(string $summary, string $filename, ?string $fromDate = null, ?string $toDate = null): string
    {
        $filepath = $this->tempDir . '/' . $filename . '.md';

        // メタデータを含む内容を作成
        $content = "# 生成日時: " . date('Y-m-d H:i:s') . "\n";

        // 期間情報の設定（期間指定があればそれを表示、なければ過去X日間を表示）
        if ($fromDate !== null && $toDate !== null) {
            $content .= "# 対象期間: {$fromDate} から {$toDate}\n";
        } else {
            $content .= "# 対象期間: 過去{$this->activityDays}日間\n";
        }

        $content .= "# プロジェクトID: {$this->projectId}\n\n";
        $content .= "---\n\n";
        $content .= $summary;

        if (file_put_contents($filepath, $content) === false) {
            throw new \Exception("一時ファイルの保存に失敗しました: {$filepath}");
        }

        return $filepath;
    }

    protected function deleteTempFile(string $filepath): void
    {
        if (file_exists($filepath)) {
            if (!unlink($filepath)) {
                echo "警告: 一時ファイルの削除に失敗しました: {$filepath}\n";
            }
        }
    }

    public function listTempFiles(): array
    {
        $files = [];
        if (is_dir($this->tempDir)) {
            $iterator = new \DirectoryIterator($this->tempDir);
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'md') {
                    $files[] = $file->getPathname();
                }
            }
        }
        return $files;
    }

    public function cleanupOldTempFiles(int $days = 7): void
    {
        $cutoffTime = time() - ($days * 24 * 60 * 60);
        $deletedCount = 0;
        
        foreach ($this->listTempFiles() as $file) {
            if (filemtime($file) < $cutoffTime) {
                if (unlink($file)) {
                    $deletedCount++;
                }
            }
        }
        
        if ($deletedCount > 0) {
            echo "{$deletedCount}個の古い一時ファイルを削除しました。\n";
        }
    }
}