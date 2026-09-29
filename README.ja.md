# Footprints

[English](README.md) | **日本語**

各ページのタイトルの横に、足跡アイコンと数字を出す MediaWiki 拡張機能です。
数字はそのページを読んだ**人数**（延べ回数ではなく、何人の違う人が読んだか）です。
クリックすると、**誰が**読んだか、最後にいつ来たかが分かります。

## 最初に：この拡張は「誰が何を読んだか」を記録します

Footprints は、どの利用者がどのページをいつ読んだかを保存し、それを Wiki を
読める全員に見せます。個々の読者が記録を拒否する仕組みはありません。
そうしないと、人数が正確な数字でなくなるからです。

想定しているのは、閲覧にアカウントが必要で、読めば足跡が残ることを全員が
知っている、**小規模で閉じた Wiki**（部活・チーム・クラスなど）です。
**導入する前に、利用者に知らせてください。**

オプションの通知（下記）は、さらに一歩踏み込みます。「あなたのページが読まれた」
ことが、見に行けば分かるだけでなく、ページの作成者の手元に届きます。
そこまでは要らない場合は、`$wgFootprintsNotifyEnabled = false` で止められます。

## 機能

- **インジケータ**：ページタイトルの横にアイコンと人数を出します。
  `Special:Footprints/<ページ名>` への普通のリンクなので、JavaScript が無くても動きます。
- **ダイアログ**：クリックすると開きます。見た目は2種類から選べます（`$wgFootprintsDialogStyle`）。
  - `simple`（既定）：OOUI のダイアログに、読者・最終閲覧日時・閲覧回数の表を出します。
  - `rich`：作り込んだカード表示です。「最終更新のあと読んだ人」の進み具合、
    14日間の閲覧グラフ、並べ替えタブ（最近来た順／よく来る順／更新後まだ読んでいない人）、
    バッジ（あなた・ページの作成者・最終編集者・常連）を出します。
    スキンのライト／ダークの切り替えに追従します。
- **Special:Footprints**：ページ名なしで開くと、読んだ人数の多いページの一覧。
  ページ名つきで開くと、そのページを読んだ全員の一覧です。
- **Echo 通知**（Echo が入っている場合）：ページの読者が 1・3・5・10・20・50・100 人に
  達したとき、作成者に知らせます。
- **API**：`action=query&list=footprints&fppage=<ページ名>`

## インストール

1. このディレクトリを `extensions/Footprints` に置きます。
2. `LocalSettings.php` に追記します。
   ```php
   wfLoadExtension( 'Footprints' );
   ```
3. `php maintenance/run.php update` を実行して、`footprint` と `footprint_log`
   テーブルを作ります。

MediaWiki 1.45 以降が必要です。動作確認は 1.46 + MariaDB で行っています。
スキーマファイルは MySQL/MariaDB 用のみです。

## 閲覧の数え方

- `BeforePageDisplay` から、`POSTSEND` の遅延更新で記録します。読者が書き込みを
  待たされることはありません。
- ページの本文そのものを表示したときだけ記録します（`OutputPage::isArticle()`）。
  履歴・差分・編集プレビュー・特別ページ・API では足跡は付きません。
- 対象は `$wgFootprintsNamespaces` の名前空間だけです（既定は標準名前空間）。
- 匿名利用者・一時アカウント・`$wgFootprintsExcludeGroups`（既定は `bot`）の
  メンバーは記録しません。
- 同じ人が同じページを前回から `$wgFootprintsCooldown` 秒（既定 300）以内に
  また開いても、閲覧回数には数えません。再読み込みや、開いたまま何度も更新した
  ページは1回として数えます。人数のほうはこの影響を一切受けません
  （`footprint` はページと利用者の組ごとに1行です）。
- `footprint_log` には、数えた閲覧1回ごとに1行を残します。rich ダイアログの
  グラフに使います。
- ページを削除すると、そのページの足跡も消えます。
- 拡張を入れる前の閲覧は記録されていないので、数字はゼロから始まります。

## 通知

通知は、次の条件をすべて満たしたときだけ送ります。

1. 読者がそのページを**初めて**開いたとき（再読み込みや再訪では送りません）。
2. **作成者以外の**読者の人数が、`$wgFootprintsNotifyMilestones` のどれかに
   ちょうど達したとき。
3. 読んだのが作成者本人ではないとき。

作成者を人数から外しているのは、ページを保存するとそのページの表示に移るため、
作成者がほぼ必ず「1人目の読者」になるからです。作成者を数えてしまうと、
「誰かが初めて読んだ」という通知がどのページでも届かなくなります。

読者1人ごとではなく節目で送るのは、小規模な Wiki では新しいページが1〜2週間で
読者を集めきってしまい、1人ごとの通知はただの雑音になるからです。

既定では Web 通知はオン、メールはオフです。どちらも利用者が個人設定で変えられます。

## 設定

| 変数 | 既定値 | 意味 |
|---|---|---|
| `$wgFootprintsNamespaces` | `[ NS_MAIN ]` | 足跡を記録・表示する名前空間 |
| `$wgFootprintsExcludeGroups` | `[ 'bot' ]` | 足跡を残さないグループ |
| `$wgFootprintsCooldown` | `300` | この秒数以内の再訪は閲覧回数に数えない（`0` で毎回数える） |
| `$wgFootprintsListLimit` | `200` | Special:Footprints と API で返す最大行数 |
| `$wgFootprintsDialogStyle` | `'simple'` | `'simple'` か `'rich'` |
| `$wgFootprintsDialogWebFonts` | `false` | rich ダイアログのみ：表示用フォントを Google Fonts から読み込む |
| `$wgFootprintsRegularViews` | `15` | rich ダイアログの「常連」バッジに必要な閲覧回数 |
| `$wgFootprintsNotifyEnabled` | `true` | 節目の通知を送る（Echo が必要） |
| `$wgFootprintsNotifyMilestones` | `[ 1, 3, 5, 10, 20, 50, 100 ]` | 通知を送る人数（作成者を除く） |

Special:Footprints と API は、`read` 権限があれば誰でも使えます。特定のグループに
限りたい場合は、`SpecialFootprints::execute()` と `ApiQueryFootprints::execute()` に
権限の確認を足してください。

## 拡張する

- フック `FootprintsViewCounted( Title $title, UserIdentity $reader, bool $isNewReader )`：
  閲覧を1回数えた後に呼ばれます（応答を返した後）。
- フック `FootprintsIndicator( OutputPage $out, array &$before )`：`$before` に足した
  HTML が、インジケータの中の、足跡リンクの左に入ります。
- JS フック `ext.footprints.dialog.footer`（rich ダイアログ）：フッターの要素を受け取ります。
  クラス `ext-footprints-footer-link` を付けたリンクを先頭に足してください。

## ライセンス

GPL-2.0-or-later。`COPYING` を参照してください。
