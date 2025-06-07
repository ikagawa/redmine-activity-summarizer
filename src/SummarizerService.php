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
    }

    /**
     * アクティビティ要約のメイン処理を実行
     */
    public function run(): void
    {
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

            // 3. 要約をRedmineに投稿
            echo "生成された要約をRedmineに投稿中...\n";

            // Wikiページとして投稿
            $date = date('Y-m-d');
            $title = "ActivitySummary{$date}";
            $project = $this->redmine->getProject($this->projectId);

            $this->redmine->updateWikiPage(
                $this->projectId,
                $title,
                $summary,
                "過去{$this->activityDays}日間のアクティビティ要約を自動生成"
            );

            echo "要約が正常に投稿されました。Wiki: {$title}\n";

        } catch (\Exception $e) {
            echo "エラーが発生しました: {$e->getMessage()}\n";
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

            // 3. 要約をRedmineに投稿
            echo "生成された要約をRedmineに投稿中...\n";

            // 課題として投稿
            $date = date('Y-m-d');
            $subject = "プロジェクトアクティビティ要約 ({$date})";

            $this->redmine->createIssue(
                $targetProjectId,
                $subject,
                $summary
            );

            echo "要約が正常に課題として投稿されました。\n";

        } catch (\Exception $e) {
            echo "エラーが発生しました: {$e->getMessage()}\n";
            exit(1);
        }
    }
}
