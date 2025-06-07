# Redmine Activity Summarizer

RedmineのアクティビティデータをPostgreSQLから取得し、Google Gemini 2.5 AIを使用して要約した後、その要約をRedmineのWikiページに投稿するPHPアプリケーションです。

## 機能

- PostgreSQLデータベースから直接Redmineのアクティビティを取得
- Google Gemini 2.5 AIを使用してアクティビティの要約を生成
- 生成された要約をRedmineのWikiページとして投稿
- プロジェクト別またはシステム全体のアクティビティ要約に対応
- 詳細なデバッグ機能とトラブルシューティング機能
- 一時ファイル管理とクリーンアップ機能

## 要件

- PHP 7.4以上
- Composer
- PostgreSQLデータベースへのアクセス（RedmineのDB）
- Redmine APIキー（API有効化が必要）
- Google Gemini APIキー

## インストール

```bash
# リポジトリをクローン
git clone https://your-repository-url.git
cd redmine-activity-summarizer

# 依存パッケージをインストール
composer install

# 環境設定ファイルをコピーして編集
cp .env.example .env
# .envファイルを編集して必要な設定を行ってください
```

## 設定

### .envファイルの設定例

```dotenv
# Redmine API設定
REDMINE_URL=http://your-redmine-instance.com  # HTTPまたはHTTPS
REDMINE_API_KEY=your_api_key_here

# PostgreSQL設定（RedmineのDB）
DB_HOST=localhost
DB_PORT=5432
DB_NAME=redmine
DB_USER=redmine_user
DB_PASSWORD=password

# Gemini API設定
GEMINI_API_KEY=your_gemini_api_key_here

# アプリケーション設定
ACTIVITY_DAYS=7  # 何日分のアクティビティを要約するか
PROJECT_ID=1     # 全体要約を投稿するRedmineプロジェクトのID
```

### 重要な設定項目

- **REDMINE_URL**: RedmineサーバーのURL（HTTPとHTTPS両対応）
- **PROJECT_ID**: 全体要約の投稿先プロジェクト（管理用プロジェクト推奨）
- **ACTIVITY_DAYS**: 要約対象期間（デフォルト7日間）

## 使い方

### 基本的な使用方法

```bash
# 全システムのアクティビティを要約してWikiに投稿
php summarize.php

# 特定のプロジェクト（例：プロジェクトID=6）のアクティビティのみを要約
php summarize.php -p 6

# 要約する日数を指定（例：14日間）
php summarize.php -d 14
```

### デバッグとトラブルシューティング

```bash
# 詳細なデバッグ情報を表示
php summarize.php --verbose

# Redmine API接続テスト
php summarize.php --test

# RedmineのURL診断（HTTPとHTTPS両方をテスト）
php summarize.php --diagnose

# SSL証明書検証を無効にして実行（自己署名証明書環境用）
php summarize.php --insecure
```

### 一時ファイル管理

```bash
# 保存されている一時ファイルの一覧表示
php summarize.php --list-temp

# 7日以上古い一時ファイルを削除
php summarize.php --cleanup
```

### コマンドライン オプション一覧

| オプション | 説明 |
|------------|------|
| `-p, --project=ID` | 特定のプロジェクトIDのアクティビティのみを要約 |
| `-d, --days=NUM` | 要約する日数を指定 |
| `-v, --verbose` | 詳細なデバッグ情報を表示 |
| `-t, --test` | Redmine API接続テストのみ実行 |
| `-D, --diagnose` | Redmine URL診断を実行 |
| `-I, --insecure` | SSL証明書の検証を無効にする |
| `-l, --list-temp` | 保存されている一時ファイルを一覧表示 |
| `-c, --cleanup` | 7日以上古い一時ファイルを削除 |
| `-e, --export` | アクティビティデータをJSONファイルにエクスポート |
| `-E, --export-project=ID` | 特定プロジェクトのデータをJSONにエクスポート |
| `-o, --output=PATH` | エクスポート時の出力ファイルパスを指定 |
| `-P, --prompt=PATH` | カスタムプロンプトファイルを指定 |
| `-h, --help` | ヘルプメッセージを表示 |

## 出力されるWikiページ

### 全体要約の場合
- **投稿先**: `.env`の`PROJECT_ID`で指定されたプロジェクト
- **Wikiページ名**: `ActivitySummary_YYYY-MM-DD`
- **内容**: 全プロジェクトのアクティビティを統合した要約

### プロジェクト別要約の場合
- **投稿先**: 指定されたプロジェクト
- **Wikiページ名**: `Project{ID}_ActivitySummary_YYYY-MM-DD`
- **内容**: 指定プロジェクトのみのアクティビティ要約

## トラブルシューティング

### SSL関連のエラー
```bash
# エラー例: error:0A0000C6:SSL routines::packet length too long
# 対策: HTTPを使用するかSSL検証を無効化
php summarize.php --insecure
```

### API接続エラー
```bash
# URL診断で問題を特定
php summarize.php --diagnose

# 接続テストで詳細確認
php summarize.php --test --verbose
```

### 権限エラー
- Redmine APIキーにWiki編集権限があることを確認
- 対象プロジェクトへのアクセス権限を確認

## ファイル構成

```
redmine-activity-summarizer/
├── bin/
│   └── summarize.php          # メインスクリプト
├── src/
│   ├── Database/
│   │   └── RedmineDatabase.php # DB接続クラス
│   ├── AI/
│   │   └── GeminiClient.php    # Gemini APIクライアント
│   ├── Redmine/
│   │   └── RedmineClient.php   # Redmine APIクライアント
│   └── SummarizerService.php   # メインサービスクラス
├── temp/                       # 一時ファイル保存ディレクトリ
├── .env.example               # 環境設定テンプレート
├── .env                       # 環境設定ファイル（要作成）
├── composer.json              # 依存関係定義
└── README.md                  # このファイル
```

## ライセンス

MIT

## 注意事項

- 本アプリケーションはRedmineのデータベースに直接アクセスします
- PostgreSQLの読み取り専用ユーザーの使用を推奨します
- API制限やクォータにご注意ください（Gemini API）
- 一時ファイルは定期的にクリーンアップしてください

## プロンプト調整用機能

### JSONデータのエクスポート

AIプロンプトの調整用に、データベースからデータをJSONファイルとして出力する機能を提供しています。

```bash
# 全体のアクティビティデータをJSONファイルにエクスポート
php bin/summarize.php --export

# 特定プロジェクトのアクティビティデータをJSONファイルにエクスポート
php bin/summarize.php --export-project=1

# 出力先ファイルパスを指定
php bin/summarize.php --export --output=/path/to/output.json

# エクスポートする日数を指定
php bin/summarize.php --export --days=30
```

エクスポートされたJSONデータは、Gemini APIプロンプトの調整や、アクティビティデータの分析に使用できます。

### カスタムプロンプトの使用

独自のプロンプトテンプレートを使用して要約を生成できます。

```bash
# カスタムプロンプトを使用して要約を生成
php bin/summarize.php --prompt=path/to/prompt_template.txt

# プロジェクト別要約でカスタムプロンプトを使用
php bin/summarize.php --project=5 --prompt=path/to/prompt_template.txt
```

プロンプトテンプレートファイル内では `{ACTIVITIES}` プレースホルダーを使用して、アクティビティデータが挿入される位置を指定できます。

### サンプルプロンプトテンプレート

いくつかのサンプルプロンプトテンプレートが `examples/prompt_templates/` ディレクトリに用意されています：

- `default_prompt.txt` - デフォルトの要約プロンプト
- `technical_summary_prompt.txt` - 技術チーム向けの詳細な要約
- `executive_summary_prompt.txt` - 経営陣向けの簡潔な要約
- `detailed_analysis_prompt.txt` - 包括的なデータ分析
