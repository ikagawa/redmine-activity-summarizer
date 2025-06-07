# プロンプトテンプレート集

このディレクトリには、様々な目的に適したプロンプトテンプレートが含まれています。

## 使用方法

```bash
php bin/summarize.php --prompt=examples/prompt_templates/technical_summary_prompt.txt
```

## プロンプト作成のガイドライン

1. **{ACTIVITIES}プレースホルダー**を必ず含めてください（アクティビティデータが挿入される位置）
2. **明確な指示**を与えてください（出力形式、長さ、焦点を当てるべき点など）
3. **対象読者**を指定すると、より適切な要約が生成されます
4. **出力形式**（Markdown、箇条書きなど）を指定するとより整った結果が得られます

## 含まれるテンプレート

| ファイル名 | 用途 | 出力形式 | 適した対象者 |
|------------|------|----------|------------|
| `default_prompt.txt` | 一般的な活動要約 | Markdown | チーム全体 |
| `technical_summary_prompt.txt` | 技術的な詳細を含む要約 | Markdown（コードブロック付き） | 開発者・エンジニア |
| `executive_summary_prompt.txt` | 簡潔なビジネス視点の要約 | 簡潔な箇条書き | マネージャー・経営陣 |
| `detailed_analysis_prompt.txt` | 詳細なデータ分析 | 構造化されたMarkdown | データアナリスト・マネージャー |

## カスタムテンプレートの作成

これらのテンプレートをベースにして、プロジェクトの特定のニーズに合わせたカスタムテンプレートを作成できます。

```bash
# テンプレートをコピーして新規作成
cp examples/prompt_templates/default_prompt.txt my_custom_prompt.txt

# 編集して使用
php bin/summarize.php --prompt=my_custom_prompt.txt
```
