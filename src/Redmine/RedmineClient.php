<?php

namespace RedmineSummarizer\Redmine;

use Redmine\Client;

class RedmineClient
{
    private Client $client;

    public function __construct(string $url, string $apiKey)
    {
        $this->client = new Client($url, $apiKey);
    }

    /**
     * 課題を作成
     * 
     * @param int $projectId プロジェクトID
     * @param string $subject 課題タイトル
     * @param string $description 課題説明
     * @return array 作成された課題情報
     */
    public function createIssue(int $projectId, string $subject, string $description): array
    {
        $data = [
            'project_id' => $projectId,
            'subject' => $subject,
            'description' => $description,
            'tracker_id' => 1 // デフォルトのトラッカーID（通常は「機能」や「タスク」）
        ];

        return $this->client->issue->create($data)->getContent();
    }

    /**
     * Wikiページを作成または更新
     * 
     * @param int $projectId プロジェクトID
     * @param string $title Wikiページタイトル
     * @param string $content Wikiページ内容
     * @param string $comments 変更コメント（更新時のみ）
     * @return array 作成/更新されたWikiページ情報
     */
    public function updateWikiPage(int $projectId, string $title, string $content, string $comments = ''): array
    {
        $project = $this->client->project->show($projectId)->getContent();
        $projectIdentifier = $project['project']['identifier'];

        try {
            // まずページが存在するか確認
            $existingPage = $this->client->wiki->show($projectIdentifier, $title)->getContent();

            // 存在する場合は更新
            $data = [
                'text' => $content,
                'comments' => $comments
            ];
            return $this->client->wiki->update($projectIdentifier, $title, $data)->getContent();

        } catch (\Exception $e) {
            // ページが存在しない場合は新規作成
            $data = [
                'text' => $content
            ];
            return $this->client->wiki->create($projectIdentifier, $title, $data)->getContent();
        }
    }

    /**
     * プロジェクト情報を取得
     * 
     * @param int $projectId プロジェクトID
     * @return array プロジェクト情報
     */
    public function getProject(int $projectId): array
    {
        return $this->client->project->show($projectId)->getContent();
    }
}
