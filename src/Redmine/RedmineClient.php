<?php

namespace RedmineSummarizer\Redmine;

class RedmineClient
{
    private $url;
    private $apiKey;

    public function __construct(string $url, string $apiKey)
    {
        $this->url = rtrim($url, '/');
        $this->apiKey = $apiKey;
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
            'issue' => [
                'project_id' => $projectId,
                'subject' => $subject,
                'description' => $description,
                'tracker_id' => 1
            ]
        ];

        return $this->makeRequest('POST', '/issues.json', $data);
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
        // プロジェクト情報を取得してidentifierを取得
        $project = $this->getProject($projectId);
        $projectIdentifier = $project['project']['identifier'];

        $data = [
            'wiki_page' => [
                'text' => $content,
                'comments' => $comments
            ]
        ];

        try {
            // ページが存在するか確認
            $this->makeRequest('GET', "/projects/{$projectIdentifier}/wiki/{$title}.json");

            // 存在する場合は更新
            return $this->makeRequest('PUT', "/projects/{$projectIdentifier}/wiki/{$title}.json", $data);
        } catch (\Exception $e) {
            // 存在しない場合は新規作成
            return $this->makeRequest('PUT', "/projects/{$projectIdentifier}/wiki/{$title}.json", $data);
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
        return $this->makeRequest('GET', "/projects/{$projectId}.json");
    }

    /**
     * Redmine APIにリクエストを送信
     *
     * @param string $method HTTPメソッド
     * @param string $endpoint エンドポイント
     * @param array|null $data 送信データ
     * @return array レスポンスデータ
     */
    private function makeRequest(string $method, string $endpoint, array $data = null): array
    {
        $url = $this->url . $endpoint;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Redmine-API-Key: ' . $this->apiKey
        ]);

        switch (strtoupper($method)) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                if ($data) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                if ($data) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
        }

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \Exception("Redmine API エラー: ステータスコード {$statusCode}, レスポンス: {$response}");
        }

        return json_decode($response, true);
    }
}