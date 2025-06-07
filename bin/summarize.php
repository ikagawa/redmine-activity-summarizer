#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use RedmineSummarizer\Database\RedmineDatabase;
use RedmineSummarizer\AI\GeminiClient;
use RedmineSummarizer\Redmine\RedmineClient;
use RedmineSummarizer\SummarizerService;
use Dotenv\Dotenv;

// .envから環境変数を読み込む
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$geminiApiKey = $_ENV['GEMINI_API_KEY'] ?? null;

if (empty($geminiApiKey)) {
    $envContent = file_get_contents(__DIR__ . '/../.env');
    if (preg_match('/^GEMINI_API_KEY=(.+)$/m', $envContent, $matches)) {
        $geminiApiKey = trim($matches[1]);
        $_ENV['GEMINI_API_KEY'] = $geminiApiKey;
    }
}

// コマンドライン引数の処理
$options = getopt('p:d:f:t:lchvDIeE:o:P:T:SM:u:', ['project:', 'days:', 'from:', 'to:', 'list-temp', 'cleanup', 'help', 'verbose', 'test', 'diagnose', 'insecure', 'export', 'export-project:', 'output:', 'prompt:', 'title:', 'show-token-info', 'model:', 'user:']);

if (isset($options['h']) || isset($options['help'])) {
    echo "使い方: php summarize.php [オプション]\n";
    echo "\n基本オプション:\n";
    echo "  -h, --help              このヘルプメッセージを表示\n";
    echo "  -v, --verbose           詳細なデバッグ情報を表示\n";
    echo "\nアクティビティ要約オプション:\n";
    echo "  -p, --project=ID        特定のプロジェクトIDのアクティビティのみを要約\n";
    echo "  -u, --user=LOGIN        特定のユーザー(login名)のアクティビティのみを要約\n";
    echo "  -d, --days=NUM          要約する日数を指定（デフォルト: 環境変数のACTIVITY_DAYS）\n";
    echo "  -P, --prompt=PATH       カスタムプロンプトファイルを指定\n";
    echo "  -T, --title=NAME        Wikiページタイトルのプレフィックスを指定\n";
    echo "  -S, --show-token-info   Gemini APIのトークン使用量情報を表示する\n";
    echo "  -M, --model=NAME        使用するLLMモデル名を指定（例: gemini-1.5-pro, gemini-1.5-flash）\n";
    echo "\n期間指定オプション:\n";
    echo "  -f, --from=DATE         開始日を指定（YYYY-MM-DD形式）\n";
    echo "  -t, --to=DATE           終了日を指定（YYYY-MM-DD形式）\n";
    echo "\n診断・テストオプション:\n";
    echo "  --test                  Redmine API接続テストのみ実行\n";
    echo "  -D, --diagnose          Redmine URL診断を実行\n";
    echo "  -I, --insecure          SSL証明書の検証を無効にする\n";
    echo "\nエクスポートオプション:\n";
    echo "  -e, --export            アクティビティデータをJSONにエクスポートして終了\n";
    echo "  -E, --export-project=ID 特定プロジェクトのデータをJSONにエクスポート\n";
    echo "  -o, --output=PATH       エクスポート時の出力ファイルパスを指定\n";
    echo "\n一時ファイル管理オプション:\n";
    echo "  -l, --list-temp         保存されている一時ファイルを一覧表示\n";
    echo "  -c, --cleanup           7日以上古い一時ファイルを削除\n";
    exit(0);
}

// デバッグモードの確認
$debug = isset($options['v']) || isset($options['verbose']);
$insecure = isset($options['I']) || isset($options['insecure']);

// 設定を取得
$dbHost = $_ENV['DB_HOST'];
$dbPort = (int)$_ENV['DB_PORT'];
$dbName = $_ENV['DB_NAME'];
$dbUser = $_ENV['DB_USER'];
$dbPassword = $_ENV['DB_PASSWORD'];

$redmineUrl = $_ENV['REDMINE_URL'];
$redmineApiKey = $_ENV['REDMINE_API_KEY'];

$geminiApiKey = $_ENV['GEMINI_API_KEY'];

$activityDays = (int)($_ENV['ACTIVITY_DAYS'] ?? 7);
$projectId = (int)($_ENV['PROJECT_ID'] ?? 1);

// コマンドライン引数で上書き
if (isset($options['d']) || isset($options['days'])) {
    $activityDays = (int)(isset($options['d']) ? $options['d'] : $options['days']);
}

if ($debug) {
    echo "=== デバッグモード有効 ===\n";
    echo "Redmine URL: {$redmineUrl}\n";
    echo "API Key: " . substr($redmineApiKey, 0, 8) . "...\n";
    echo "Project ID: {$projectId}\n";
    echo "Activity Days: {$activityDays}\n";
    echo "Insecure Mode: " . ($insecure ? 'Yes' : 'No') . "\n";
    echo "========================\n";
}

// トークン情報の表示有無を設定
$includeTokenInfo = isset($options['S']) || isset($options['show-token-info']);

// サービスを初期化
try {
    $database = new RedmineDatabase($dbHost, $dbPort, $dbName, $dbUser, $dbPassword);
    $gemini = new GeminiClient($geminiApiKey);
    $redmine = new RedmineClient($redmineUrl, $redmineApiKey, $debug, $insecure);

    $summarizer = new SummarizerService($database, $gemini, $redmine, $activityDays, $projectId, $debug, $includeTokenInfo);

    // URL診断実行
    if (isset($options['D']) || isset($options['diagnose'])) {
        $summarizer->diagnoseRedmineUrl();
        exit(0);
    }

    // 接続テストのみ実行
    if (isset($options['test'])) {
        $summarizer->testRedmineConnection();
        echo "接続テストが完了しました。\n";
        exit(0);
    }

    // 一時ファイル一覧表示
    if (isset($options['l']) || isset($options['list-temp'])) {
        $tempFiles = $summarizer->listTempFiles();
        if (empty($tempFiles)) {
            echo "一時ファイルはありません。\n";
        } else {
            echo "保存されている一時ファイル:\n";
            foreach ($tempFiles as $file) {
                $date = date('Y-m-d H:i:s', filemtime($file));
                $size = round(filesize($file) / 1024, 2);
                echo "  {$file} ({$size}KB, {$date})\n";
            }
        }
        exit(0);
    }

    // 古い一時ファイルのクリーンアップ
    if (isset($options['c']) || isset($options['cleanup'])) {
        $summarizer->cleanupOldTempFiles();
        exit(0);
    }

    // 出力ファイルパスの設定
    $outputPath = null;
    if (isset($options['o']) || isset($options['output'])) {
        $outputPath = isset($options['o']) ? $options['o'] : $options['output'];
    }

    // カスタムプロンプトの設定
    $customPrompt = null;
    if (isset($options['P']) || isset($options['prompt'])) {
        $promptFile = isset($options['P']) ? $options['P'] : $options['prompt'];
        if (file_exists($promptFile)) {
            $customPrompt = file_get_contents($promptFile);
            if ($debug) {
                echo "カスタムプロンプトを読み込みました: {$promptFile}\n";
            }
        } else {
            echo "警告: プロンプトファイルが見つかりません: {$promptFile}\n";
        }
    }

    // Wikiタイトルプレフィックスの設定
    $wikiTitlePrefix = null;
    if (isset($options['T']) || isset($options['title'])) {
        $wikiTitlePrefix = isset($options['T']) ? $options['T'] : $options['title'];
        if ($debug) {
            echo "Wikiタイトルプレフィックスを設定: {$wikiTitlePrefix}\n";
        }
    }

    // ユーザー指定の設定
    $userLogin = null;
    if (isset($options['u']) || isset($options['user'])) {
        $userLogin = isset($options['u']) ? $options['u'] : $options['user'];
        if ($debug) {
            echo "特定ユーザーのアクティビティを処理: {$userLogin}\n";
        }
    }

    // LLMモデル名の設定
    $modelName = null;
    if (isset($options['M']) || isset($options['model'])) {
        $modelName = isset($options['M']) ? $options['M'] : $options['model'];
        if ($debug) {
            echo "LLMモデルを設定: {$modelName}\n";
        }
    }

    // 期間指定の設定
    $fromDate = null;
    $toDate = null;
    if ((isset($options['f']) || isset($options['from'])) && (isset($options['t']) || isset($options['to']))) {
        $fromDate = isset($options['f']) ? $options['f'] : $options['from'];
        $toDate = isset($options['t']) ? $options['t'] : $options['to'];

        // 日付形式の検証
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
            echo "エラー: 日付形式が無効です。YYYY-MM-DD形式で指定してください。\n";
            exit(1);
        }

        // 日付の妥当性チェック
        $fromDateTime = new DateTime($fromDate);
        $toDateTime = new DateTime($toDate);

        if ($fromDateTime > $toDateTime) {
            echo "エラー: 開始日が終了日より後になっています。\n";
            exit(1);
        }

        if ($debug) {
            echo "期間指定: {$fromDate} から {$toDate}\n";
        }
    }

    // 全体アクティビティをJSONにエクスポート
    if (isset($options['e']) || isset($options['export'])) {
        echo "アクティビティデータをJSONファイルにエクスポートします...\n";
        // 日付範囲指定がある場合
        if ($fromDate && $toDate) {
            $filePath = $database->exportActivitiesByDateRangeToJson($fromDate, $toDate, $outputPath, $userLogin);
        } else {
            $filePath = $database->exportActivitiesToJson($activityDays, $outputPath);
        }
        echo "エクスポート完了: {$filePath}\n";
        exit(0);
    }

    // 特定プロジェクトのアクティビティをJSONにエクスポート
    if (isset($options['E']) || isset($options['export-project'])) {
        $exportProjectId = isset($options['E']) ? (int)$options['E'] : (int)$options['export-project'];
        echo "プロジェクトID {$exportProjectId} のアクティビティデータをJSONファイルにエクスポートします...\n";
        // 日付範囲指定がある場合
        if ($fromDate && $toDate) {
            $filePath = $database->exportProjectActivitiesByDateRangeToJson($exportProjectId, $fromDate, $toDate, $outputPath);
        } else {
            $filePath = $database->exportProjectActivitiesToJson($exportProjectId, $activityDays, $outputPath);
        }
        echo "エクスポート完了: {$filePath}\n";
        exit(0);
    }

    // 特定のプロジェクトのみを処理する場合
    if (isset($options['p']) || isset($options['project'])) {
        $targetProjectId = (int)(isset($options['p']) ? $options['p'] : $options['project']);

        // 日付範囲指定がある場合
        if ($fromDate && $toDate) {
            $summarizer->runForProjectWithDateRange($targetProjectId, $fromDate, $toDate, $customPrompt, $wikiTitlePrefix, $modelName, $userLogin);
        } else {
            $summarizer->runForProject($targetProjectId, $customPrompt, $wikiTitlePrefix, $modelName, $userLogin);
        }
    } else {
        // 全体のアクティビティを処理
        // 日付範囲指定がある場合
        if ($fromDate && $toDate) {
            $summarizer->runWithDateRange($fromDate, $toDate, $customPrompt, $wikiTitlePrefix, $modelName, $userLogin);
        } else {
            $summarizer->run($customPrompt, $wikiTitlePrefix, $modelName, $userLogin);
        }
    }

    echo "処理が完了しました。\n";
    exit(0);

} catch (Exception $e) {
    echo "エラーが発生しました: {$e->getMessage()}\n";
    exit(1);
}