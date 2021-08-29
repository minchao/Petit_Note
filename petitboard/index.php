<?php
//Petit-board (c)さとぴあ @satopian 2020-2021
//1スレッド1ログファイル形式のスレッド式掲示板

//設定項目
// 最大スレッド数
$max=100;

$mode = filter_input(INPUT_POST,'mode');
if($mode==='regist'){
	post();
}
$page=filter_input(INPUT_GET,'page');

function post(){
	global $max;
//POSTされた内容を取得
$sub = t((string)filter_input(INPUT_POST,'sub'));
$name = t((string)filter_input(INPUT_POST,'name'));
$com = t((string)filter_input(INPUT_POST,'com'));
$resno = t((string)filter_input(INPUT_POST,'resno'));
$tempfile = $_FILES['imgfile']['tmp_name'] ?? ''; // 一時ファイル名
// $filename = $_FILES['imgfile']['name']; // 本来のファイル名
$imgfile='';
if ($tempfile && $_FILES['imgfile']['error'] === UPLOAD_ERR_OK){
	$time = time();
	$imgfile = $time.substr(microtime(),2,3);	//画像ファイル名
	$img_type=mime_content_type($tempfile);//190603
	$ext=getImgType ($img_type);
	if($ext){
		$imgfile=$imgfile.$ext;
		move_uploaded_file($tempfile,'./src/'.$imgfile);
	}	
}


if(!$sub){
	$sub='無題';
}
if(!$name){
	$name='anonymous';
}
$com=str_replace(["\r\n","\r","\n",],'"\n"',$com);

setcookie("namec",$name,time()+(60*60*24*30),0,"",false,true);
if(!$imgfile&&!$com){
error('何か書いて下さい。');
}

//全体ログを開く
$alllog_arr=file('./log/alllog.txt');
$alllog=end($alllog_arr);
$line='';
//書き込まれるログの書式
if($resno){//レスの時はスレッド別ログに追記
	$r_line = "$resno\t$sub\t$name\t$com\t$imgfile\t$resno\n";
	file_put_contents('./log/'.$resno.'.txt',$r_line,FILE_APPEND);
	chmod('./log/'.$resno.'.txt',0600);	
	foreach($alllog_arr as $i =>$val){
		list($_no)=explode("\t",$alllog_arr[$i]);
		if($resno==$_no){
			$line = $val;//レスが付いたスレッドを$lineに保存。あとから配列を追加して上げる
			unset($alllog_arr[$i]);//レスが付いたスレッドを全体ログからいったん削除
			break;
		}
	}
	
} else{
	list($no)=explode("\t",$alllog);
	//最後の記事ナンバーに+1
	$no=trim($no)+1;
	$line = "$no\t$sub\t$name\t$com\t$imgfile\t$resno\n";
	file_put_contents('./log/'.$no.'.txt',$line);//新規投稿の時は、記事ナンバーのファイルを作成して書き込む
	chmod('./log/'.$no.'.txt',0600);
}
	array_push($alllog_arr,$line);//全体ログに新しい投稿を追加
//スレッド数オーバー
$countlog=count($alllog_arr);
for($i=0;$i<$countlog-$max;++$i){//30スレッド以上のログは削除
	list($_no,,,,,$imgfile,)=explode("\t",$alllog_arr[$i]);
	safe_unlink('./log/'.$_no.'.txt');//スレッド個別ログファイル削除
	safe_unlink('src/'.$imgfile);//画像削除
	unset($alllog_arr[$i]);//全体ログ記事削除

}

file_put_contents('./log/alllog.txt',$alllog_arr,LOCK_EX);//全体ログに書き込む
chmod('./log/alllog.txt',0600);

}

$alllog_arr=file('./log/alllog.txt');//全体ログを読み込む
$count_alllog=count($alllog_arr);
krsort($alllog_arr);

//ページ番号から10スレッド分とりだす
$alllog_arr=array_slice($alllog_arr,$page,10,false);
//oyaのループ
foreach($alllog_arr as $oya => $alllog){
	
		list($no)=explode("\t",$alllog);
		$fp = fopen("./log/$no.txt", "r");//個別スレッドのログを開く
		while ($line = fgetcsv($fp, 0, "\t")) {
		list($no,$sub,$name,$com,$imgfile,$resno)=$line;
		$res=[];
		$res=[
			'no' => $no,
			'sub' => $sub,
			'name' => $name,
			'com' => $com,
			'img' => $imgfile,
			'resno' => $resno,
		];
		$res['com']=str_replace('"\n"',"\n",$res['com']);
		$out[$oya][]=$res;
	}	
	fclose($fp);

}
//タブ除去
function t($str){
	return str_replace("\t","",$str);
}
//エスケープと改行
function h($str){
	$str=htmlspecialchars($str,ENT_QUOTES,"utf-8");
	return nl2br($str);
}
//mimeから拡張子
function getImgType ($img_type) {

	switch ($img_type) {
		case "image/gif" : return ".gif";
		case "image/jpeg" : return ".jpg";
		case "image/png" : return ".png";
		case "image/webp" : return ".webp";
		default : return '';
	}
	
}
//ファイルがあれば削除
function safe_unlink ($path) {
	if ($path && is_file($path)) {
		return unlink($path);
	}
	return false;
}

//Cookie
$namec=(string)filter_input(INPUT_COOKIE,'namec');

?>
<!DOCTYPE html>
<html lang="ja">
<head>
	<meta charset="UTF-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<style>
	body {
		margin: 10px;
		color: #555;
	}
	.container {
		max-width: 800px;
		margin: 35px auto;
		color: #555;
	}
	h1 {
    font-size: 26px;
	}
	h2 {font-size: 18px;background-color: #ffe6e6;}
	form.postform {
    text-align: center;
	}
	textarea.post_com {
    width: 300px;
    height: 120px;
	margin: 10px;
	}
	textarea.rescom {
	width: 300px;
	height: 60px;
	margin: 10px 0;
	}

	form.resform {
		text-align: right;
	}
	a {
    color: #555;
	}

	a:hover{
		text-decoration: none;
	}
	img {
    max-height: 500px;
    margin: 8px 0;
	}
	</style>
	<title>掲示板</title>
</head>
<body>
<div class="container">
	<h1>Petit-board</h1>
<form action="index.php" method="POST" enctype="multipart/form-data" class="postform">
題名:<input type="text" name="sub"><br>
名前:<input type="text" name="name" value="<?=$namec?>"><br>
<textarea name="com" class="post_com"></textarea>
<input type="hidden" name="mode" value="regist">
<br>
<input type="file" name="imgfile" size="35" accept="image/*">
<br>
<input type="submit" value="スレッドを立てる">
</form>
<hr>
	<!-- 親のループ -->
<?php foreach($out as $ress) : ?>
<h2><?= h($ress[0]['sub'])?></h2>
	<!-- スレッドのループ -->
<?php foreach($ress as $res) : ?>
名前:<?= h($res['name'])?><br>
<?php if($res['img']):?>
	<img src="src/<?=h($ress[0]['img'])?>" alt="">
	<br>
	<?php endif;?>
<?= h($res['com'])?>
<hr class="reshr">
<?php endforeach;?>
<!-- 返信フォーム -->
<form action="index.php" method="POST" enctype="multipart/form-data" class="resform">
名前:<input type="text" name="name"  value="<?=$namec?>"><br>
<textarea name="com" class="rescom"></textarea>
<input type="hidden" name="resno" value="<?=h($res['no'])?>">
<input type="hidden" name="mode" value="regist">
<br>
<input type="submit" value="返信">
</form>
<hr>

<?php endforeach;?>

<?php for($i = 0; $i < $count_alllog ; $i+=10) :?>
	<?php if($page==$i):?>
		 [<?=($i/10)?>]
	<?php else: ?>
	<?php if($i==0):?>
		[<a href="?page=0">0</a>]
	<?php else:?>	
	  [<a href="?page=<?=$i?>"><?=($i/10)?></a>]
	<?php endif;?>
	<?php endif;?>
	<?php endfor ;?>
</div>
</body>
</html>
	<?php
function error($str){
	?>
<!DOCTYPE html>
<html lang="ja">
<head>
	<meta charset="UTF-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<style>
	body {
		margin: 10px;
		color: #555;
	}
	.container {
		max-width: 800px;
		margin: 35px auto;
		color: #555;
	}
	h1 {
    font-size: 26px;
	}
	h2 {font-size: 18px;background-color: #ffe6e6;}
	form.postform {
    text-align: center;
	}
	a {
    color: #555;
	}

	a:hover{
		text-decoration: none;
	}
	.error{font-size: 18px;
		line-height: 1.8;
	}
	</style>
	<title>掲示板</title>
</head>
<body>
<div class="container error">
<?=$str?><br>
<a href="./">もどる</a>
<?php exit;?>
 
<?php
}
?>

