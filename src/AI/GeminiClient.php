<?php

namespace RedmineSummarizer\AI;

use Google\Cloud\AIPlatform\V1\PredictionServiceClient;
use Google\Cloud\AIPlatform\V1\GenerateContentRequest;
use Google\Cloud\AIPlatform\V1\GenerateContentResponse;
use Google\Cloud\AIPlatform\V1\Content;
use Google\Cloud\AIPlatform\V1\Part;

class GeminiClient
{
    private string $apiKey;
    private string $model;

    public function __construct(string $apiKey, string $model = 'gemini-1.5-pro')
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
    }

    public function setModel(string $modelName): void
    {
        $this->model = $modelName;
    }

    /**
     * アクティビティの要約を生成
     * 
     * @param array $activities アクティビティの配列
     * @param string|null $customPrompt カスタムプロンプト（nullの場合はデフォルトプロンプト使用）
     * @param bool $includeTokenInfo トークン情報を含めるかどうか
     * @return string 要約テキスト
     */
    public function summarizeActivities(array $activities, ?string $customPrompt = null, bool $includeTokenInfo = true): string
    {
        // アクティビティデータをテキストに変換
        $activitiesText = $this->formatActivitiesForPrompt($activities);

        // プロンプトを作成
        if ($customPrompt === null) {
            // デフォルトプロンプト
            $prompt = <<<EOT
以下はRedmineプロジェクト管理システムからのアクティビティ情報です。このデータを分析して、最近のプロジェクト活動の要約を作成してください。

要約は以下の構造にしてください:
1. 全体的な活動の概要（追加された課題数、更新された課題数など）
2. 主要な進捗や変更点
3. 現在進行中の作業の状況
4. チームの協力やコミュニケーションに関する洞察

要約は簡潔で情報量が多く、マネージャーやチームメンバーが最近の活動を素早く理解できるものにしてください。Markdown形式で出力してください。

アクティビティデータ:
$activitiesText

要約:
EOT;
        } else {
            // カスタムプロンプトの場合、アクティビティデータを挿入
            $prompt = str_replace('{ACTIVITIES}', $activitiesText, $customPrompt);
        }

        try {
            // Gemini APIにリクエスト
            $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";

            $requestData = [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.2,
                    'topK' => 40,
                    'topP' => 0.95,
                    'maxOutputTokens' => 1024,
                ]
            ];

            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json'
            ]);

            $response = curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($statusCode !== 200) {
                throw new \Exception("Gemini API エラー: ステータスコード {$statusCode}, レスポンス: {$response}");
            }

            $responseData = json_decode($response, true);

            if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
                $summaryText = $responseData['candidates'][0]['content']['parts'][0]['text'];

                // トークン使用量情報を追加（指定された場合）
                if ($includeTokenInfo && isset($responseData['usageMetadata'])) {
                    $promptTokenCount = $responseData['usageMetadata']['promptTokenCount'] ?? 'N/A';
                    $candidatesTokenCount = $responseData['usageMetadata']['candidatesTokenCount'] ?? 'N/A';
                    $totalTokenCount = $responseData['usageMetadata']['totalTokenCount'] ?? 'N/A';

                    $tokenInfo = "\n\n---\n\n";
                    $tokenInfo .= "**Gemini API トークン使用量**\n";
                    $tokenInfo .= "* プロンプトトークン: {$promptTokenCount}\n";
                    $tokenInfo .= "* 生成トークン: {$candidatesTokenCount}\n";
                    $tokenInfo .= "* 合計トークン: {$totalTokenCount}\n";

                    $summaryText .= $tokenInfo;
                }

                return $summaryText;
            } else {
                throw new \Exception("Gemini API レスポンス形式エラー: {$response}");
            }

        } catch (\Exception $e) {
            throw new \Exception("要約生成エラー: " . $e->getMessage());
        }
    }

    /**
     * アクティビティデータをプロンプト用にフォーマット
     * 
     * @param array $activities アクティビティの配列
     * @return string フォーマットされたテキスト
     */
    private function formatActivitiesForPrompt(array $activities): string
    {
        $text = '';
        foreach ($activities as $index => $activity) {
            $text .= "【アクティビティ {$index}】\n";
            $text .= "タイプ: {$activity['activity_type']}\n";
            $text .= "プロジェクト: {$activity['project_name']}\n";
            $text .= "課題ID: {$activity['issue_id']}\n";
            $text .= "課題タイトル: {$activity['issue_subject']}\n";
            $text .= "作成者: {$activity['author']}\n";
            $text .= "日時: {$activity['created_at']}\n";

            if (!empty($activity['issue_description'])) {
                $text .= "説明: {$activity['issue_description']}\n";
            }

            if (!empty($activity['comment'])) {
                $text .= "コメント: {$activity['comment']}\n";
            }

            $text .= "\n";
        }

        return $text;
    }
}
