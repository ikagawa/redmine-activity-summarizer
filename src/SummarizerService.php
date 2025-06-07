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

    public function __construct(
        RedmineDatabase $database,
        GeminiClient $gemini,
        RedmineClient $redmine,
        int $activityDays,
        int $projectId
    ) {
        $this->database = $database;
        $this->gemini = $gemini;
        $this->redmine = $redmine;
        $this->activityDays = $activityDays;
        $this->projectId = $projectId;
        
        // 一時ファイル保存ディレクトリを設定
        $this->tempDir = __DIR__ . '/../temp';
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }

    /**
     * アクティビティ要約のメイン処理を実行
     */
    public function run(): void
    {
        $tempFile = null;
        
        try {
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
            $summary = $this->gemini->summarizeActivities($activities);

            // 3. 要約を一時ファイルに保存
            $date = date('Y-m-d_H-i-s');
            $tempFile = $this->saveSummaryToTempFile($summary, "all_activities_{$date}");
            echo "要約を一時ファイルに保存しました: {$tempFile}\n";

            // 4. 要約をRedmineに投稿
            echo "生成された要約をRedmineに投稿中...\n";

            // Wikiページとして投稿
            $wikiDate = date('Y-m-d');
            $title = "ActivitySummary{$wikiDate}";
            $project = $this->redmine->getProject($this->projectId);

            $this->redmine->updateWikiPage(
                $this->projectId,
                $title,
                $summary,
                "過去{$this->activityDays}日間のアクティビティ要約を自動生成"
            );

            echo "要約が正常に投稿されました。Wiki: {$title}\n";

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
     */
    public function runForProject(int $targetProjectId): void
    {
        $tempFile = null;
        
        try {
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
            $summary = $this->gemini->summarizeActivities($activities);

            // 3. 要約を一時ファイルに保存
            $date = date('Y-m-d_H-i-s');
            $tempFile = $this->saveSummaryToTempFile($summary, "project_{$targetProjectId}_{$date}");
            echo "要約を一時ファイルに保存しました: {$tempFile}\n";

            // 4. 要約をRedmineに投稿
            echo "生成された要約をRedmineに投稿中...\n";

            // 課題として投稿
            $issueDate = date('Y-m-d');
            $subject = "プロジェクトアクティビティ要約 ({$issueDate})";

            $this->redmine->createIssue(
                $targetProjectId,
                $subject,
                $summary
            );

            echo "要約が正常に課題として投稿されました。\n";

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
     * 要約を一時ファイルに保存
     * 
     * @param string $summary 要約内容
     * @param string $filename ファイル名（拡張子なし）
     * @return string 保存されたファイルパス
     */
    private function saveSummaryToTempFile(string $summary, string $filename): string
    {
        $filepath = $this->tempDir . '/' . $filename . '.md';
        
        // メタデータを含む内容を作成
        $content = "# 生成日時: " . date('Y-m-d H:i:s') . "\n";
        $content .= "# 対象期間: 過去{$this->activityDays}日間\n";
        $content .= "# プロジェクトID: {$this->projectId}\n\n";
        $content .= "---\n\n";
        $content .= $summary;
        
        if (file_put_contents($filepath, $content) === false) {
            throw new \Exception("一時ファイルの保存に失敗しました: {$filepath}");
        }
        
        return $filepath;
    }

    /**
     * 一時ファイルを削除
     * 
     * @param string $filepath ファイルパス
     */
    private function deleteTempFile(string $filepath): void
    {
        if (file_exists($filepath)) {
            if (!unlink($filepath)) {
                echo "警告: 一時ファイルの削除に失敗しました: {$filepath}\n";
            }
        }
    }

    /**
     * 一時ファイル一覧を取得
     * 
     * @return array 一時ファイルのパス一覧
     */
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

    /**
     * 古い一時ファイルをクリーンアップ
     * 
     * @param int $days 何日前より古いファイルを削除するか
     */
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