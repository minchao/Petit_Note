<?php
//Petit Note (c)さとぴあ @satopian 2021-2022
//1スレッド1ログファイル形式のスレッド式画像掲示板
$lang = ($http_langs = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '')
  ? explode( ',', $http_langs )[0] : '';
$en= (stripos($lang,'ja')!==0) ? true : false;

if(!is_file(__DIR__.'/functions.php')){
	return die(__DIR__.'/functions.php'.($en ? ' does not exist.':'がありません。'));
}
require_once(__DIR__.'/functions.php');

if (function_exists('check_file') && $err = check_file(__DIR__.'/config.php')) {
	return die($err);
}
if (function_exists('check_file') && $err = check_file(__DIR__.'/thumbnail_gd.php')) {
	return die($err);
}
if (function_exists('check_file') && $err = check_file(__DIR__.'/noticemail.inc')) {
	return die($err);
}

require_once(__DIR__.'/config.php');
require_once(__DIR__.'/thumbnail_gd.php');
require_once(__DIR__.'/noticemail.inc');

//テンプレート
$skindir='template/'.$skindir;

$petit_ver='v0.19.5';
$petit_lot='lot.220529';

if(!isset($functions_ver)||$functions_ver<20220525){
	return error($en?'Please update functions.php to the latest version.':'functions.phpを最新版に更新してください。');
}
if(!isset($thumbnail_gd_ver)||$thumbnail_gd_ver<20220322){
	return error($en?'Please update thumbmail_gd.php to the latest version.':'thumbnail_gd.phpを最新版に更新してください。');
}

if(!$max_log){
	return error($en?'The maximum number of threads has not been set.':'最大スレッド数が設定されていません。');
}
if(!isset($admin_pass)||!$admin_pass){
	return error($en?'The administrator password has not been set.':'管理者パスワードが設定されていません。');
}
$max_log=($max_log<500) ? 500 : $max_log;//最低500スレッド
$max_com= isset($max_com) ? $max_com : 1000;
$sage_all= isset($sage_all) ? $sage_all : false;
$view_other_works= isset($view_other_works) ? $view_other_works : true;
$deny_all_posts= isset($deny_all_posts) ? $deny_all_posts : (isset($denny_all_posts) ? $denny_all_posts : false);
$latest_var=isset($latest_var) ? $latest_var : true;
$mode = filter_input(INPUT_POST,'mode');
$mode = $mode ? $mode :filter_input(INPUT_GET,'mode');
$page=filter_input(INPUT_GET,'page',FILTER_VALIDATE_INT);
$page= $page ? $page : 0; 
$resno=filter_input(INPUT_GET,'resno');

$usercode = t(filter_input(INPUT_COOKIE, 'usercode'));//nullならuser-codeを発行
$userip = get_uip();
//user-codeの発行
if(!$usercode){//falseなら発行
	$usercode = substr(crypt(md5($userip.date("Ymd", time())),'id'),-12);
	//念の為にエスケープ文字があればアルファベットに変換
	$usercode = strtr($usercode,"!\"#$%&'()+,/:;<=>?@[\\]^`/{|}~","ABCDEFGHIJKLMNOabcdefghijklmn");
}
setcookie("usercode", $usercode, time()+(86400*365),"","",false,true);//1年間

//初期化
init();
deltemp();//テンポラリ自動削除
switch($mode){
	case 'regist':
		if($deny_all_posts){
			return view();	
		}
		return post();
	case 'paint':
		return paint();
	case 'paintcom':
		return paintcom();
	case 'pchview':
		return pchview();
	case 'to_continue':
		return to_continue();
	case 'contpaint':
		$type = filter_input(INPUT_POST, 'type');
		if($type==='rep') check_cont_pass();
		return paint();
	case 'picrep':
		return img_replace();
	case 'before_del':
		return confirmation_before_deletion();
	case 'edit_form':
		return edit_form();
	case 'edit':
		return edit();
	case 'del':
		return del();
	case 'userdel':
		return userdel_mode();
	case 'adminin':
		return admin_in();
	case 'admin_del':
		return admin_del();
	case 'adminpost':
		return adminpost();
	case 'aikotoba':
		return aikotoba();
	case 'view_nsfw':
		return view_nsfw();
	case 'logout_admin':
		return logout_admin();
	case 'logout':
		return logout();
	case 'catalog':
		return catalog($page);
	case 'download':
		return download_app_dat($page);
	default:
		if($resno){
			return res($resno);
		}
		return view($page);
}

//投稿処理
function post(){
	global $max_log,$max_res,$max_kb,$use_aikotoba,$use_upload,$use_res_upload,$use_diary,$max_w,$max_h,$use_thumb;
	global $allow_coments_only,$res_max_w,$res_max_h,$admin_pass,$name_input_required,$max_com,$max_px,$sage_all,$en;

	if($use_aikotoba){
		check_aikotoba();
	}
	check_csrf_token();

	//POSTされた内容を取得
	$usercode = t(filter_input(INPUT_COOKIE, 'usercode'));
	$userip =t(get_uip());
	//ホスト取得
	$host = t(gethostbyaddr($userip));

	$sub = t((string)filter_input(INPUT_POST,'sub'));
	$name = t((string)filter_input(INPUT_POST,'name'));
	$com = t((string)filter_input(INPUT_POST,'com'));
	$url = t((string)filter_input(INPUT_POST,'url',FILTER_VALIDATE_URL));
	$resto = t((string)filter_input(INPUT_POST,'resto',FILTER_VALIDATE_INT));
	$pwd=t((string)filter_input(INPUT_POST, 'pwd'));//パスワードを取得
	$sage = $sage_all ? true : filter_input(INPUT_POST,'sage',FILTER_VALIDATE_BOOLEAN);
	$check_elapsed_days=false;

	//NGワードがあれば拒絶
	Reject_if_NGword_exists_in_the_post();

	$pwd=$pwd ? $pwd : t(filter_input(INPUT_COOKIE,'pwdc'));//未入力ならCookieのパスワード
	if(!$pwd){//それでも$pwdが空なら
		srand((double)microtime()*1000000);
		$pwd = substr(md5(uniqid(rand())),2,15);
		$pwd = strtr($pwd,"!\"#$%&'()+,/:;<=>?@[\\]^`/{|}~","ABCDEFGHIJKLMNOabcdefghijklmn");
	}
	if(strlen($pwd) < 6) return error($en? 'The password is too short. At least 6 characters.':'パスワードが短すぎます。最低6文字。');

	$upfile='';
	$imgfile='';
	$w='';
	$h='';
	$tool='';
	$time = time();
	$time = $time.substr(microtime(),2,3);	//投稿時刻

	$adminpost=adminpost_valid();

	//お絵かきアップロード
	$pictmp = filter_input(INPUT_POST, 'pictmp',FILTER_VALIDATE_INT);
	list($picfile,) = explode(",",filter_input(INPUT_POST, 'picfile'));
	$painttime ='';
	$pictmp2=false;
	$tempfile='';
	if($pictmp===2){//ユーザーデータを調べる

		if(!$picfile) return error($en? 'Posting failed.':'投稿に失敗しました。');
		$tempfile = TEMP_DIR.$picfile;
		$picfile=pathinfo($tempfile, PATHINFO_FILENAME );//拡張子除去
		//選択された絵が投稿者の絵か再チェック
		if (!$picfile || !is_file(TEMP_DIR.$picfile.".dat")) {
			return error($en? 'Posting failed.':'投稿に失敗しました。');
		}
		//ユーザーデータから情報を取り出す
		$fp = fopen(TEMP_DIR.$picfile.".dat", "r");
		$userdata = fread($fp, 1024);
		fclose($fp);
		list($uip,$uhost,,,$ucode,,$starttime,$postedtime,$uresto,$tool) = explode("\t", rtrim($userdata)."\t");
		if(($ucode != $usercode) && ($uip != $userip)){return error($en? 'Posting failed.':'投稿に失敗しました。');}
		$uresto=filter_var($uresto,FILTER_VALIDATE_INT);
		$resto = $uresto ? $uresto : $resto;//変数上書き$userdataのレス先を優先する
		$resto=(string)$resto;//(string)厳密な型
		//描画時間を$userdataをもとに計算
		if($starttime && is_numeric($starttime) && $postedtime && is_numeric($postedtime)){
			$painttime=(int)$postedtime-(int)$starttime;
		}
		if($resto && $picfile && !$use_res_upload && !$adminpost){
			return error($en? 'You are not logged in in diary mode.':'日記にログインしていません。');
		}
		$pictmp2=true;//お絵かきでエラーがなかった時にtrue;

	}

	if(!$resto && $use_diary && !$adminpost){
		return error($en? 'You are not logged in in diary mode.':'日記にログインしていません。');
	}

	if($resto && !is_file(LOG_DIR."{$resto}.log")){//エラー処理
		if(!$pictmp2){//お絵かきではない時は
			return error($en? 'The article does not exist.':'記事がありません。');
		}
		$resto='';//レス先がないお絵かきは新規投稿扱いにする。
	}
	$count_r_arr=0;
	$r_oya='';
	$r_no='';
	if($resto){//エラー処理
		
		if(!is_file(LOG_DIR."{$resto}.log")){
			return error($en? 'The article does not exist.':'記事がありません。');
		}
		$rp=fopen(LOG_DIR."$resto.log","r");
		$r_arr=[];
		while($line = fgets($rp)){
			if(!trim($line)){
				continue;
			}
			$r_arr[]=$line;
		}
		closeFile ($rp);

		list($r_no,$oyasub,$n_,$v_,$c_,$u_,$img_,$_,$_,$thumb_,$pt_,$md5_,$to_,$pch_,$postedtime,$fp_time_,$h_,$uid_,$h_,$r_oya)=explode("\t",trim($r_arr[0]));
		//レスファイルの1行目のチェック。経過日数、ログの1行目が'oya'かどうか確認。
		$check_elapsed_days = check_elapsed_days($postedtime);
		$count_r_arr=count($r_arr);

		if($pictmp2){//お絵かきの時は新規投稿にする

			//お絵かきの時に日数を経過していたら新規投稿。
			//お絵かきの時に最大レス数を超過していたら新規投稿。
			if($resto && (!$check_elapsed_days || $count_r_arr>$max_res || $r_no!==$resto || $r_oya!=='oya')){
				$resto='';
			}
		}
		if($resto && ($r_no!==$resto || $r_oya!=='oya')){
			return error($en? 'The article does not exist.':'記事がありません。');
		}
		//お絵かき以外。
		if($resto && !$check_elapsed_days){//指定した日数より古いスレッドには投稿できない。
			return error($en? 'This thread is too old to post.':'このスレッドには投稿できません。');
		}
		if($resto && $count_r_arr>$max_res){//最大レス数超過。
			return error($en?'The maximum number of replies has been exceeded.':'最大レス数を超過しています。');
		}

		$sub='Re: '.$oyasub;

	}
	//ファイルアップロード
	$up_tempfile = isset($_FILES['imgfile']['tmp_name']) ? $_FILES['imgfile']['tmp_name'] : ''; // 一時ファイル名
	if(isset($_FILES['imgfile']['error']) && in_array($_FILES['imgfile']['error'],[1,2])){//容量オーバー
		return error($en? "Upload failed.The file size is too big.":"アップロードに失敗しました。ファイルサイズが大きすぎます。");
	} 
	if ($up_tempfile && $_FILES['imgfile']['error'] === UPLOAD_ERR_OK && ($use_upload || $adminpost)){

		if($resto && $up_tempfile && !$use_res_upload && !$adminpost){
			safe_unlink($up_tempfile);
			return error($en? 'You are not logged in in diary mode.':'日記にログインしていません。');
		}

		$img_type = isset($_FILES['imgfile']['type']) ? $_FILES['imgfile']['type'] : '';

		if (!in_array($img_type, ['image/gif', 'image/jpeg', 'image/png','image/webp'])) {
			safe_unlink($up_tempfile);
			return error($en? 'This file is an unsupported format.':'対応していないフォーマットです。');
		}
		$upfile=TEMP_DIR.$time.'.tmp';
		move_uploaded_file($up_tempfile,$upfile);
		$tool = 'upload'; 
		
	}

	//お絵かきアップロード
	if($pictmp2 && is_file($tempfile)){

		$upfile=TEMP_DIR.$time.'.tmp';
			copy($tempfile, $upfile);
			chmod($upfile,0606);
	}
	//POSTされた値をログファイルに格納する書式にフォーマット
	$formatted_post=create_formatted_text_from_post($name,$sub,$url,$com);
	$name = $formatted_post['name'];
	$sub = $formatted_post['sub'];
	$url = $formatted_post['url'];
	$com = $formatted_post['com'];

	if(!$name){
		if($name_input_required){
			safe_unlink($upfile);
			return error($en?'Please enter your name.':'名前がありません。');
		}else{
			$name='anonymous';
		}
	}

	if(!$upfile&&!$com){
		return error($en?'Please write something.':'何か書いて下さい。');
	}

	if(!$resto && !$allow_coments_only && !$upfile && !$adminpost){
		return error($en?'Please attach an image.':'画像を添付してください。');
	}

	$hash = $pwd ? password_hash($pwd,PASSWORD_BCRYPT,['cost' => 5]) : '';

	setcookie("namec",$name,time()+(60*60*24*30),"","",false,true);
	setcookie("urlc",$url,time()+(60*60*24*30),"","",false,true);
	setcookie("pwdc",$pwd,time()+(60*60*24*30),"","",false,true);


	//ユーザーid
	$userid = t(getId($userip));

	$verified = ($adminpost||($admin_pass && $pwd===$admin_pass)) ? 'adminpost' : ''; 

	//全体ログを開く
	$fp=fopen(LOG_DIR."alllog.log","r+");
	if(!$fp){
		safe_unlink($upfile);
		return error($en?'The operation failed.':'失敗しました。');
	}
	flock($fp, LOCK_EX);
	$alllog_arr=[];
	while ($_line = fgets($fp)) {
		if(!trim($_line)){
			continue;
		}
		$alllog_arr[]=$_line;
	}
	$img_md5='';
	//チェックするスレッド数。画像ありなら15、コメントのみなら5 
	$n= $upfile ? 15 : 5;
	$chk_log_arr=array_slice($alllog_arr,0,$n,false);
	$chk_com=[];
	$chk_images=[];
	$chk_resnos=[];
	foreach($chk_log_arr as $chk_log){
		list($chk_resno)=explode("\t",$chk_log);
		$chk_resnos[]=$chk_resno;
	}
	if($resto){//レスの時はレスファイルをチェックに追加
		$chk_resnos=array_merge($chk_resnos,[$resto]);
	} 
	foreach($chk_resnos as $chk_resno){
		if(is_file(LOG_DIR."{$chk_resno}.log")){

		$cp=fopen(LOG_DIR."{$chk_resno}.log","r");
		while($line=fgets($cp)){
			if(!trim($line)){
				continue;
			}
			$chk_ex_line=explode("\t",trim($line));
			list($no_,$sub_,$name_,$verified_,$com_,$url_,$imgfile_,$w_,$h_,$thumbnail_,$painttime_,$log_md5_,$tool_,$pchext_,$time_,$first_posted_time_,$host_,$userid_,$hash_,$oya_)=$chk_ex_line;
			if(((int)$time-(int)$time_)<1000){//投稿時刻の重複回避が主目的
				safe_unlink($upfile);
				closeFile($fp);
				safe_unlink($upfile);
				return error($en? 'Please wait a little.':'少し待ってください。');
			}
			if($host === $host_){
				$chk_com[$time_]=$chk_ex_line;//コメント
			}
			if($upfile && $imgfile_){
				$chk_images[$time_]=$chk_ex_line;//画像
			}
		}
		fclose($cp);
		}
	}
	krsort($chk_com);
	$chk_com=array_slice($chk_com,0,20,false);

	foreach($chk_com as $line){
		list($_no_,$_sub_,$_name_,$_verified_,$_com_,$_url_,$_imgfile_,$_w_,$_h_,$_thumbnail_,$_painttime_,$_log_md5_,$_tool_,$_pchext_,$_time_,$_first_posted_time_,$_host_,$_userid_,$_hash_,$_oya_)=$line;

		if($com && ($com === $_com_)){
			closeFile($fp);
			safe_unlink($upfile);
			return error($en?'Post once by this comment.':'同じコメントがありました。');
		}

		// 画像アップロードと画像なしそれぞれの待機時間
		if(($upfile && (time()-substr($_time_,0,-3))<30)||(!$upfile && (time()-substr($_time_,0,-3))<15)){
			closeFile($fp);
			safe_unlink($upfile);
			return error($en? 'Please wait a little.':'少し待ってください。');
		}
	}
	if($upfile && is_file($upfile)){

		if(!$pictmp2){//実体データの縮小
			$max_px=isset($max_px) ? $max_px : 1024;
			thumb(TEMP_DIR,$time.'.tmp',$time,$max_px,$max_px,['toolarge'=>1]);
		}	
		clearstatcache();
		$filesize=filesize($upfile);
		if($filesize > 800 * 1024){//指定サイズを超えていたら
			if ($im_jpg = png2jpg($upfile)) {//PNG→JPEG自動変換

				if(filesize($im_jpg)<$filesize){//JPEGのほうが小さい時だけ
					rename($im_jpg,$upfile);//JPEGで保存
					chmod($upfile,0606);
				} else{//PNGよりファイルサイズが大きくなる時は
					unlink($im_jpg);//作成したJPEG画像を削除
				}
			}
		}
		if(!$pictmp2){
			clearstatcache();
			if(filesize($upfile) > $max_kb*1024){
				closeFile($fp);
				safe_unlink($upfile);
			return error($en? "Upload failed. File size exceeds {$max_kb}kb.":"アップロードに失敗しました。ファイル容量が{$max_kb}kbを超えています。");
			}
		}

		list($w,$h)=getimagesize($upfile);
		$_img_type=mime_content_type($upfile);
		$ext=getImgType ($_img_type);
		if (!$ext) {
			closeFile($fp);
			safe_unlink($upfile);
			return error($en? 'This file is an unsupported format.':'対応していないフォーマットです。');
		}

		//同じ画像チェック アップロード画像のみチェックしてお絵かきはチェックしない
		if(!$pictmp2){
			
			$img_md5=md5_file($upfile);
			foreach($chk_images as $line){
				list($no_,$sub_,$name_,$verified_,$com_,$url_,$imgfile_,$w_,$h_,$thumbnail_,$painttime_,$log_md5,$tool_,$pchext_,$time_,$first_posted_time_,$host_,$userid_,$hash_,$oya_)=$line;
				if($log_md5 && ($log_md5 === $img_md5)){
					closeFile($fp);
					safe_unlink($upfile);
					return error($en?'Image already exists.':'同じ画像がありました。');
				}
			}
		}
		$imgfile=$time.$ext;	
		rename($upfile,IMG_DIR.$imgfile);
	}
	$src='';
	$pchext = '';
	if($pictmp2 && $imgfile){
		//PCHファイルアップロード
	// .pch, .spch,.chi,.psd ブランク どれかが返ってくる
		if ($pchext = check_pch_ext(TEMP_DIR.$picfile,['upload'=>true])) {

			$src = TEMP_DIR.$picfile.$pchext;
			$dst = IMG_DIR.$time.$pchext;
			if(copy($src, $dst)){
				chmod($dst,0606);
			}
		}

	}
	$thumbnail='';
	if($imgfile && is_file(IMG_DIR.$imgfile)){
		
		$max_w = $resto ? $res_max_w : $max_w; 
		$max_h = $resto ? $res_max_h : $max_h; 
		//縮小表示
		list($w,$h)=image_reduction_display($w,$h,$max_w,$max_h);
		//サムネイル
		if($use_thumb){
			if(thumb(IMG_DIR,$imgfile,$time,$max_w,$max_h)){
				$thumbnail='thumbnail';
			}
		}
	}
	//ログの番号の最大値
	$no_arr = [];
	foreach($alllog_arr as $i => $_alllog){
		list($log_no,)=explode("\t",$_alllog);
		$no_arr[]=$log_no;
	}

	$max_no=0;
	if(!empty($no_arr)){
		$max_no=max($no_arr);
	}
	//書き込むログの書式
	$line='';
	$newline='';
	if($resto){//レスの時はスレッド別ログに追記
		//ファイルロックした状態で再度開く
		$rp=fopen(LOG_DIR."{$resto}.log","r+");
		if(!$rp){
			closeFile($fp);
			safe_unlink(IMG_DIR.$imgfile);
			return error($en?'The operation failed.':'失敗しました。');
		}
		flock($rp, LOCK_EX);
		$r_arr=[];
		$r_oya='';
		$r_no='';
		while ($line = fgets($rp)) {
			if(!trim($line)){
				continue;
			}
			$r_arr[]=$line;
		}
		if(empty($r_arr)){
			closeFile($rp);
			closeFile($fp);
			safe_unlink(IMG_DIR.$imgfile);
			return error($en?'The operation failed.':'失敗しました。');
		}
		//ファイルロックがかかった状態で再確認。
		//レス先はoya?
		list($r_no,,,,,,,,,,,,,,,,,,,$r_oya)=explode("\t",trim($r_arr[0]));
		if($r_no!==$resto||$r_oya!=='oya'){
			closeFile($rp);
			closeFile($fp);
			safe_unlink(IMG_DIR.$imgfile);
			return error($en? 'The article does not exist.':'記事がありません。');
		}

		$r_line = "$resto\t$sub\t$name\t$verified\t$com\t$url\t$imgfile\t$w\t$h\t$thumbnail\t$painttime\t$img_md5\t$tool\t$pchext\t$time\t$time\t$host\t$userid\t$hash\tres\n";
		$new_rline=	implode("",$r_arr).$r_line;

		writeFile($rp,$new_rline);
		closeFile($rp);

		chmod(LOG_DIR.$resto.'.log',0600);
		if(!$sage){
			foreach($alllog_arr as $i =>$val){
			list($_no)=explode("\t",$val);
			if($resto==$_no){
				$newline = $val;//レスが付いたスレッドを$newlineに保存。あとから全体ログの先頭に追加して上げる
				unset($alllog_arr[$i]);//レスが付いたスレッドを全体ログからいったん削除
				break;
				}
			}
		}

	}else{
		//最後の記事ナンバーに+1
		$no=$max_no+1;
		$newline = "$no\t$sub\t$name\t$verified\t$com\t$url\t$imgfile\t$w\t$h\t$thumbnail\t$painttime\t$img_md5\t$tool\t$pchext\t$time\t$time\t$host\t$userid\t$hash\toya\n";
		file_put_contents(LOG_DIR.$no.'.log',$newline,LOCK_EX);//新規投稿の時は、記事ナンバーのファイルを作成して書き込む
		chmod(LOG_DIR.$no.'.log',0600);
	}

	//保存件数超過処理
	$countlog=count($alllog_arr);
	if($max_log && $countlog && ($max_log<=$countlog)){
		for($i=$max_log-1; $i<$countlog;++$i){

		if(!isset($alllog_arr[$i]) || !trim($alllog_arr[$i])){
			continue;
		}
		list($d_no,)=explode("\t",$alllog_arr[$i]);
		if(is_file(LOG_DIR."{$d_no}.log")){

			$dp = fopen(LOG_DIR."{$d_no}.log", "r");//個別スレッドのログを開く
			flock($dp, LOCK_EX);

			while ($line = fgets($dp)) {
				if(!trim($line)){
					continue;
				}
				list($d_no,$_sub,$_name,$_verified,$_com,$_url,$d_imgfile,$_w,$_h,$_thumbnail,$_painttime,$_log_md5,$_tool,$_pchext,$d_time,$_first_posted_time,$_host,$_userid,$_hash,$_oya)=explode("\t",trim($line));

				delete_files ($d_imgfile, $d_time);//一連のファイルを削除

			}
		safe_unlink(LOG_DIR.$d_no.'.log');//スレッド個別ログファイル削除
		closeFile($dp);
		}	
		unset($alllog_arr[$i]);//全体ログ記事削除
		}
	}
	$newline.=implode("",$alllog_arr);


	writeFile ($fp, $newline);
	closeFile($fp);

	chmod(LOG_DIR.'alllog.log',0600);

	//ワークファイル削除
	safe_unlink($src);
	safe_unlink($tempfile);
	safe_unlink($up_tempfile);
	safe_unlink($upfile);
	safe_unlink(TEMP_DIR.$picfile.".dat");

	global $send_email,$to_mail,$root_url,$boardname;

	if($send_email){
	//template_ini.phpで未定義の時の初期値
	//このままでよければ定義不要
	defined('NOTICE_MAIL_TITLE') or define('NOTICE_MAIL_TITLE', '記事題名');
	defined('NOTICE_MAIL_IMG') or define('NOTICE_MAIL_IMG', '投稿画像');
	defined('NOTICE_MAIL_THUMBNAIL') or define('NOTICE_MAIL_THUMBNAIL', 'サムネイル画像');
	defined('NOTICE_MAIL_ANIME') or define('NOTICE_MAIL_ANIME', 'アニメファイル');
	defined('NOTICE_MAIL_URL') or define('NOTICE_MAIL_URL', '記事URL');
	defined('NOTICE_MAIL_REPLY') or define('NOTICE_MAIL_REPLY', 'へのレスがありました');
	defined('NOTICE_MAIL_NEWPOST') or define('NOTICE_MAIL_NEWPOST', '新規投稿がありました');

		$data['to'] = $to_mail;
		$data['name'] = $name;
		$data['option'][] = 'URL,'.$url;
		$data['option'][] = NOTICE_MAIL_TITLE.','.$sub;
		if($imgfile) $data['option'][] = NOTICE_MAIL_IMG.','.$root_url.IMG_DIR.$imgfile;//拡張子があったら
		if(is_file(THUMB_DIR.$time.'s.jpg')) $data['option'][] = NOTICE_MAIL_THUMBNAIL.','.$root_url.THUMB_DIR.$time.'s.jpg';
		if($resto){
			$data['subject'] = '['.$boardname.'] No.'.$resto.NOTICE_MAIL_REPLY;
			$data['option'][] = "\n".NOTICE_MAIL_URL.','.$root_url.'?res='.$resto;
		}else{
			$data['subject'] = '['.$boardname.'] '.NOTICE_MAIL_NEWPOST;
			$data['option'][] = "\n".NOTICE_MAIL_URL.','.$root_url.'?res='.$no;
		}

		$data['comment'] = str_replace('"\n"',"\n",$com);

		noticemail::send($data);

	}

	//多重送信防止
	if($resto){
		return header('Location: ./?resno='.$resto.'#'.$time);
	}
	
return header('Location: ./');

}
//お絵かき画面
function paint(){

	global $boardname,$skindir,$pmax_w,$pmax_h,$en;

	$app = (string)filter_input(INPUT_POST,'app');
	$picw = filter_input(INPUT_POST,'picw',FILTER_VALIDATE_INT);
	$pich = filter_input(INPUT_POST,'pich',FILTER_VALIDATE_INT);
	$usercode = t((string)filter_input(INPUT_COOKIE, 'usercode'));
	$resto = t((string)filter_input(INPUT_POST, 'resto',FILTER_VALIDATE_INT));
	if(strlen($resto>1000)){
		return error($en?'Unknown error':'問題が発生しました。');
	}

	if($picw < 300) $picw = 300;
	if($pich < 300) $pich = 300;
	if($picw > $pmax_w) $picw = $pmax_w;
	if($pich > $pmax_h) $pich = $pmax_h;

	setcookie("appc", $app , time()+(60*60*24*30),"","",false,true);//アプレット選択
	setcookie("picwc", $picw , time()+(60*60*24*30),"","",false,true);//幅
	setcookie("pichc", $pich , time()+(60*60*24*30),"","",false,true);//高さ

	$mode = filter_input(INPUT_POST, 'mode');

	$imgfile='';
	$pchfile='';
	$img_chi='';
	$img_klecks='';
	$anime=true;
	$rep=false;
	$paintmode='paintcom';

	session_sta();

	$adminpost=adminpost_valid();

	//pchファイルアップロードペイント
	if($adminpost){

		$pchfilename = isset($_FILES['pchup']['name']) ? basename($_FILES['pchup']['name']) : '';
		
		$pchtmp=isset($_FILES['pchup']['tmp_name']) ? $_FILES['pchup']['tmp_name'] : '';

		if(isset($_FILES['pchup']['error']) && in_array($_FILES['pchup']['error'],[1,2])){//容量オーバー
			return error($en? 'The file size is too big.':'ファイルサイズが大きすぎます。');
		} 

		if ($pchtmp && $_FILES['pchup']['error'] === UPLOAD_ERR_OK){
	
			$time = time().substr(microtime(),2,3);
			$pchext=pathinfo($pchfilename, PATHINFO_EXTENSION);
			$pchext=strtolower($pchext);//すべて小文字に
			//拡張子チェック
			if (!in_array($pchext, ['pch','chi','psd'])) {
				return error($en?'This file does not supported by the ability to load uploaded files onto the canvas.Supported formats are pch and chi.':'アップロードペイントで使用できるファイルはpch、chi、psdです。');
			}
			$pchup = TEMP_DIR.'pchup-'.$time.'-tmp.'.$pchext;//アップロードされるファイル名

			if(move_uploaded_file($pchtmp, $pchup)){//アップロード成功なら続行

				$pchup=TEMP_DIR.basename($pchup);//ファイルを開くディレクトリを固定
				if(!in_array(mime_content_type($pchup),["application/octet-stream","application/gzip","image/vnd.adobe.photoshop"])){
					safe_unlink($pchup);
					return error($en?'This file does not supported':'ファイル形式が一致しません。');
				}
				if($pchext==="pch"){
					$app='neo';
					$pchfile = $pchup;
				} elseif($pchext==="chi"){
					$app='chi';
					$img_chi = $pchup;
				} elseif($pchext==="psd"){
					$app='klecks';
					$img_klecks = $pchup;
				}
			}
		}
	}
	$repcode='';
	if($mode==="contpaint"){

		$imgfile = (string)filter_input(INPUT_POST,'imgfile');
		$ctype = (string)filter_input(INPUT_POST, 'ctype');
		$type = (string)filter_input(INPUT_POST, 'type');
		$no = filter_input(INPUT_POST, 'no',FILTER_VALIDATE_INT);
		$time = (string)filter_input(INPUT_POST, 'time');

		if(($type!=='rep') && is_file(LOG_DIR."{$no}.log")){
		
			$rp=fopen(LOG_DIR."{$no}.log","r");
			while ($line = fgets($rp)) {
				if(!trim($line)){
					continue;
				}
				list($no_,$sub_,$name_,$verified_,$com_,$url_,$imgfile_,$w_,$h_,$thumbnail_,$painttime_,$log_md5_,$tool_,$pchext_,$time_,$first_posted_time_,$host_,$userid_,$hash_,$oya_)=explode("\t",trim($line));
				if($time===$time_ && (int)$no==$no_){
					$resto=(trim($oya_)==='res') ? $no_ : '';
					break;
				}
			}
			closeFile ($rp);
		}

		list($picw,$pich)=getimagesize(IMG_DIR.$imgfile);//キャンバスサイズ

		$_pch_ext = check_pch_ext(IMG_DIR.$time,['upload'=>true]);

		if($ctype=='pch'&& $_pch_ext){//動画から続き
			$pchfile = IMG_DIR.$time.$_pch_ext;
		}

		if($ctype=='img' && is_file(IMG_DIR.$imgfile)){//画像から続き
			$anime=false;
			$animeform = false;
			$anime= false;
			$imgfile = IMG_DIR.$imgfile;
			if($_pch_ext==='.chi'){
				$img_chi =IMG_DIR.$time.'.chi';
			}
			if($_pch_ext==='.psd'){
				$img_klecks =IMG_DIR.$time.'.psd';
			}
		}
		if($type==='rep'){//画像差し換え
			$rep=true;
			$pwd = t((string)filter_input(INPUT_POST, 'pwd'));
			$pwd=$pwd ? $pwd : t((string)filter_input(INPUT_COOKIE,'pwdc'));//未入力ならCookieのパスワード
			if(strlen($pwd) > 100) return error($en? 'Password is too long.':'パスワードが長すぎます。');
			if($pwd){
				$pwd=openssl_encrypt ($pwd,CRYPT_METHOD, CRYPT_PASS, true, CRYPT_IV);//暗号化
				$pwd=bin2hex($pwd);//16進数に
			}
			$userip = get_uip();
			$paintmode='picrep';
			$id=$time;	//テンプレートでも使用。
			$repcode = substr(crypt(md5($no.$id.$userip.$pwd.date("Ymd", time())),time()),-8);
			//念の為にエスケープ文字があればアルファベットに変換
			$repcode = strtr($repcode,"!\"#$%&'()+,/:;<=>?@[\\]^`/{|}~","ABCDEFGHIJKLMNOabcdefghijklmn");
		}
	}

	$parameter_day = date("Ymd");//JavaScriptのキャッシュ制御

	switch($app){
		case 'chi'://ChickenPaint
		
			$tool='chi';
			// HTML出力
			$templete='paint_chi.html';
			return include __DIR__.'/'.$skindir.$templete;

		case 'klecks':

			$tool ='klecks';
			$templete='paint_klecks.html';
			return include __DIR__.'/'.$skindir.$templete;

		case 'neo'://PaintBBS NEO

			$tool='neo';
			$appw = $picw + 150;//NEOの幅
			$apph = $pich + 172;//NEOの高さ
			if($apph < 560){$apph = 560;}//最低高
			//動的パレット
			$palettetxt = $en? (is_file('palette_en.txt') ? 'palette_en.txt':'palette.txt') : 'palette.txt';  
			$lines =file($palettetxt);
			$initial_palette = 'Palettes[0] = "#000000\n#FFFFFF\n#B47575\n#888888\n#FA9696\n#C096C0\n#FFB6FF\n#8080FF\n#25C7C9\n#E7E58D\n#E7962D\n#99CB7B\n#FCECE2\n#F9DDCF";';
			$pal=[];
			$DynP=[];
			foreach ( $lines as $i => $line ) {
				$line=str_replace(["\r","\n","\t"],"",$line);
				$line=h($line);
				list($pid,$pname,$pal[0],$pal[2],$pal[4],$pal[6],$pal[8],$pal[10],$pal[1],$pal[3],$pal[5],$pal[7],$pal[9],$pal[11],$pal[12],$pal[13]) = explode(",", $line);
				$DynP[]=$pname;
				$p_cnt=$i+1;
				$palettes = 'Palettes['.$p_cnt.'] = "#';
				ksort($pal);
				$palettes.=implode('\n#',$pal);
				$palettes.='";';
				$arr_pal[$i] = $palettes;
			}
			$palettes=$initial_palette.implode('',$arr_pal);
			$palsize = count($DynP) + 1;
			foreach ($DynP as $p){
				$arr_dynp[] = $p;
			}
			// HTML出力
			$templete='paint_neo.html';
			return include __DIR__.'/'.$skindir.$templete;

		default:
			return error($en?'The operation failed.':'失敗しました。');
	}

}
// お絵かきコメント 
function paintcom(){
	global $use_aikotoba,$boardname,$home,$skindir,$sage_all,$en;
	$token=get_csrf_token();
	$userip = get_uip();
	$usercode = filter_input(INPUT_COOKIE,'usercode');
	//テンポラリ画像リスト作成
	$uresto = '';
	$handle = opendir(TEMP_DIR);
	$tmps = [];
	while ($file = readdir($handle)) {
		if(!is_dir($file) && pathinfo($file, PATHINFO_EXTENSION)==='dat') {
			$fp = fopen(TEMP_DIR.$file, "r");
			$userdata = fread($fp, 1024);
			fclose($fp);
			list($uip,$uhost,$uagent,$imgext,$ucode,,$starttime,$postedtime,$uresto,$tool) = explode("\t", rtrim($userdata));
			$file_name = pathinfo($file, PATHINFO_FILENAME);
			$uresto = $uresto ? 'res' :''; 
			if(is_file(TEMP_DIR.$file_name.$imgext)){ //画像があればリストに追加
				if($ucode === $usercode||$uip === $userip){
					$tmps[] = [$file_name.$imgext,$uresto];
				}
			}
		}
	}
	closedir($handle);

	if(!empty($tmps)){
		$pictmp = 2;
		sort($tmps);
		reset($tmps);
		foreach($tmps as $tmp){
			list($tmpfile,$resto)=$tmp;
			$tmp_img['src'] = TEMP_DIR.$tmpfile;
			$tmp_img['srcname'] = $tmpfile;
			$tmp_img['slect_src_val'] = $tmpfile.','.$resto;
			$tmp_img['date'] = date("Y/m/d H:i", filemtime($tmp_img['src']));
			$out['tmp'][] = $tmp_img;
		}
	}
	$aikotoba=aikotoba_valid();
	if(!$use_aikotoba){
		$aikotoba=true;
	}
	$namec = filter_input(INPUT_COOKIE,'namec');
	$pwdc=filter_input(INPUT_COOKIE,'pwdc');
	$urlc=(string)filter_input(INPUT_COOKIE,'urlc');

	// HTML出力
	$templete='paint_com.html';
	return include __DIR__.'/'.$skindir.$templete;
}

//コンティニュー前画面
function to_continue(){

	global $boardname,$use_diary,$use_aikotoba,$set_nsfw,$skindir,$en;

	$appc=(string)filter_input(INPUT_COOKIE,'appc');
	$pwdc=filter_input(INPUT_COOKIE,'pwdc');


	$no = (string)filter_input(INPUT_GET, 'no',FILTER_VALIDATE_INT);
	$id = (string)filter_input(INPUT_GET, 'id',FILTER_VALIDATE_INT);

	$flag = false;

	if(is_file(LOG_DIR."{$no}.log")){
		
		$rp=fopen(LOG_DIR."{$no}.log","r");
		while ($line = fgets($rp)) {
			if(!trim($line)){
				continue;
			}
			list($_no,$sub,$name,$verified,$com,$url,$imgfile,$w,$h,$thumbnail,$painttime,$log_md5,$tool,$pchext,$time,$first_posted_time,$host,$userid,$hash,$oya)=explode("\t",trim($line));
			if($id===$time && $no===$_no){
				$flag=true;
				break;
			}
		}
		closeFile ($rp);
	}
	if(!$flag || !$imgfile || !is_file(IMG_DIR.$imgfile)){//画像が無い時は処理しない
		return error($en? 'The article does not exist.':'記事がありません。');
	}
	$picfile = IMG_DIR.$imgfile;
	list($picw, $pich) = getimagesize($picfile);

	$select_app = false;
	$app_to_use = false;
	$ctype_pch = false;
	$download_app_dat=true;
	if(($pchext==='.pch')&&is_file(IMG_DIR.$time.'.pch')){
		$ctype_pch = true;
		$app_to_use = "neo";
		
	}elseif(($pchext==='.chi')&&is_file(IMG_DIR.$time.'.chi')){
		$app_to_use = 'chi';
	}elseif(($pchext==='.psd')&&is_file(IMG_DIR.$time.'.psd')){
		$app_to_use = 'klecks';
	}else{
		$select_app = true;
		$download_app_dat=false;
	}
	//日記判定処理
	session_sta();
	$adminpost=adminpost_valid();
	$adminmode = ($adminpost||admindel_valid());
	$aikotoba=aikotoba_valid();

	if(!$use_aikotoba){
	$aikotoba=true;
	}
	// nsfw
	$nsfwc=filter_input(INPUT_COOKIE,'nsfwc',FILTER_VALIDATE_BOOLEAN);

	// HTML出力
	$templete='continue.html';
	return include __DIR__.'/'.$skindir.$templete;
	
}

//アプリ固有ファイルのダウンロード
function download_app_dat(){

	global $en;

	$pwd=filter_input(INPUT_POST,'pwd');
	$no = (string)filter_input(INPUT_POST, 'no',FILTER_VALIDATE_INT);
	$id = (string)filter_input(INPUT_POST, 'id',FILTER_VALIDATE_INT);

	if(!is_file(LOG_DIR."{$no}.log")){
		return error($en? 'The article does not exist.':'記事がありません。');
	}

	$rp=fopen(LOG_DIR."{$no}.log","r");
	$flag=false;
	while ($line = fgets($rp)) {
		if(!trim($line)){
			continue;
		}
		list($_no,$sub,$name,$verified,$com,$url,$imgfile,$w,$h,$thumbnail,$painttime,$log_md5,$tool,$pchext,$time,$first_posted_time,$host,$userid,$hash,$oya)=explode("\t",trim($line));
		if($id==$time && $no===$_no){
			if(!adminpost_valid()&&!admindel_valid()&&(!$pwd || !password_verify($pwd,$hash))){
				return error($en?'Password is incorrect.':'パスワードが違います。');
			}
			$flag=true;
			break;

		} 
	}
	closeFile ($rp);
	$filepath= ($flag && is_file(IMG_DIR.$time.$pchext)) ? IMG_DIR.$time.$pchext : '';
	if(!$filepath){
		return error($en?'The operation failed.':'失敗しました。');
	}
	$mime_type = mime_content_type($filepath);
	header('Content-Type: '.$mime_type);
	header('Content-Length: '.filesize($filepath));
	header('Content-Disposition: attachment; filename="'.h(basename($filepath)).'"');

	readfile($filepath);
}

// 画像差し換え
function img_replace(){

	global $use_thumb,$max_w,$max_h,$res_max_w,$res_max_h,$max_px,$en,$use_upload;

	$no = t((string)filter_input(INPUT_POST, 'no',FILTER_VALIDATE_INT));
	$no = $no ? $no :t((string)filter_input(INPUT_GET, 'no',FILTER_VALIDATE_INT));
	$id = t((string)filter_input(INPUT_POST, 'id',FILTER_VALIDATE_INT));
	$id = $id ? $id :t((string)filter_input(INPUT_GET, 'id',FILTER_VALIDATE_INT));

	$getpwd = t((string)filter_input(INPUT_GET, 'pwd'));
	$postpwd = t((string)filter_input(INPUT_POST, 'pwd'));
	$repcode = t((string)filter_input(INPUT_GET, 'repcode'));
	$userip = t(get_uip());
	//ホスト取得
	$host = t(gethostbyaddr($userip));
	//ユーザーid
	$userid = t(getId($userip));
	$getpwd = $getpwd ? hex2bin($getpwd): '';//バイナリに
	$pwd = $getpwd ? 
	openssl_decrypt($getpwd,CRYPT_METHOD, CRYPT_PASS, true, CRYPT_IV):$postpwd;//復号化

	if(strlen($pwd) > 100) return error($en? 'Password is too long.':'パスワードが長すぎます。');

	$admindel=admindel_valid();

	//アップロード画像の差し換え
	$up_tempfile = isset($_FILES['imgfile']['tmp_name']) ? $_FILES['imgfile']['tmp_name'] : ''; // 一時ファイル名
	if (isset($_FILES['imgfile']['error']) && $_FILES['imgfile']['error'] === UPLOAD_ERR_NO_FILE){
		return error($en?'Please attach an image.':'画像を添付してください。');
	} 
	if(isset($_FILES['imgfile']['error']) && in_array($_FILES['imgfile']['error'],[1,2])){//容量オーバー
		return error($en? "Upload failed.The file size is too big.":"アップロードに失敗しました。ファイルサイズが大きすぎます。");
	} 
	$tool='';
	if ($up_tempfile && $_FILES['imgfile']['error'] === UPLOAD_ERR_OK && ($use_upload || $admindel)){

		$img_type = isset($_FILES['imgfile']['type']) ? $_FILES['imgfile']['type'] : '';

		if (!in_array($img_type, ['image/gif', 'image/jpeg', 'image/png','image/webp'])) {
			safe_unlink($up_tempfile);
			return error($en? 'This file is an unsupported format.':'対応していないフォーマットです。');
		}

		check_csrf_token();
		$tool = 'upload'; 
		
	}
	$tempfile='';
	$file_name='';
	$starttime='';
	$postedtime='';
	if(!$up_tempfile && ($tool!=='upload')){
		/*--- テンポラリ捜査 ---*/
		$find=false;
		$handle = opendir(TEMP_DIR);
		while ($file = readdir($handle)) {
			if(!is_dir($file) && pathinfo($file, PATHINFO_EXTENSION)==='dat') {
				$fp = fopen(TEMP_DIR.$file, "r");
				$userdata = fread($fp, 1024);
				fclose($fp);
				list($uip,$uhost,$uagent,$imgext,$ucode,$urepcode,$starttime,$postedtime,$uresto,$tool) = explode("\t", rtrim($userdata)."\t");//区切りの"\t"を行末に
				$file_name = pathinfo($file, PATHINFO_FILENAME );//拡張子除去
				//画像があり、認識コードがhitすれば抜ける
			
				if($file_name && is_file(TEMP_DIR.$file_name.$imgext) && $urepcode === $repcode){
					$find=true;
					break;
				}
			}
		}
		closedir($handle);
		if(!$find){
		return error($en?'The operation failed.':'失敗しました。');
		}
		$tempfile=TEMP_DIR.$file_name.$imgext;
	}
	if($up_tempfile && ($tool==='upload') && !is_file($up_tempfile)){
		return error($en?'Please attach an image.':'画像を添付してください。');
	}
	//ログ読み込み
	if(!is_file(LOG_DIR."{$no}.log")){

		if($tool==='upload'){//該当記事が無い時はエラー
			return error($en? 'The article does not exist.':'記事がありません。');
		} 
		return paintcom();//該当記事が無い時は新規投稿。
	}

	$fp=fopen(LOG_DIR."alllog.log","r+");
	flock($fp, LOCK_EX);
	$alllog_arr=[];
	while ($_line = fgets($fp)) {
		if(!trim($_line)){
			continue;
		}
		$alllog_arr[]=$_line;	
	}
	if(empty($alllog_arr)){
		closeFile($fp);
		return error($en?'The operation failed.':'失敗しました。');
	}

	$rp=fopen(LOG_DIR."{$no}.log","r+");
	flock($rp, LOCK_EX);
	$r_arr=[];
	while ($line = fgets($rp)) {
		if(!trim($line)){
			continue;
		}
		$r_arr[]=$line;
	}
	if(empty($r_arr)){
		closeFile($rp);
		closeFile($fp);
		return error($en?'The operation failed.':'失敗しました。');
	}

	$flag=false;
	foreach($r_arr as $i => $line){
		list($_no,$_sub,$_name,$_verified,$_com,$_url,$_imgfile,$_w,$_h,$_thumbnail,$_painttime,$_log_md5,$_tool,$_pchext,$_time,$_first_posted_time,$_host,$_userid,$_hash,$_oya)=explode("\t",trim($line));
		if($id===$_time && $no===$_no){

			if(($tool==='upload') && ($_tool!=='upload')) {

				closeFile($rp);
				closeFile($fp);
				return error($en?'The operation failed.':'失敗しました。');
			}

			if(!$admindel){
				if(!$pwd || !password_verify($pwd,$_hash)){
					closeFile($rp);
					closeFile($fp);
					return error($en?'The operation failed.':'失敗しました。');
				}
			}
			$flag=true;
			break;
		}
	}
	if(!check_elapsed_days($_time)&&!$admindel){//指定日数より古い画像差し換えは新規投稿にする
		closeFile($rp);
		closeFile($fp);
		return paintcom();
	}

	if(!$flag){
		closeFile($rp);
		closeFile($fp);
		return error($en?'The article was not found.':'見つかりませんでした。');
	}
	$time = time().substr(microtime(),2,3);

	$upfile=TEMP_DIR.$time.'.tmp';
	if(($tool==='upload')&&($_tool==='upload')){
		if(is_file($up_tempfile)){
			move_uploaded_file($up_tempfile,$upfile);
		}
	}else{
		if(is_file($tempfile)){
			copy($tempfile, $upfile);
		}
	}
	if(!is_file($upfile)){
		closeFile($rp);
		closeFile($fp);
		return error($en?'The operation failed.':'失敗しました。');
	} 
	chmod($upfile,0606);
	if(($tool==='upload')&&($_tool==='upload')){//実体データの縮小
		$max_px=isset($max_px) ? $max_px : 1024;
		thumb(TEMP_DIR,$time.'.tmp',$time,$max_px,$max_px,['toolarge'=>1]);
	}	

	$filesize=filesize($upfile);
	if($filesize > 800 * 1024){//指定サイズを超えていたら
		if ($im_jpg = png2jpg($upfile)) {//PNG→JPEG自動変換

			if(filesize($im_jpg)<$filesize){//JPEGのほうが小さい時だけ
				rename($im_jpg,$upfile);//JPEGで保存
				chmod($upfile,0606);
			} else{//PNGよりファイルサイズが大きくなる時は
				unlink($im_jpg);//作成したJPEG画像を削除
			}
		}
	}
		
	$img_type=mime_content_type($upfile);

	$imgext = getImgType($img_type);

	if (!$imgext) {
		closeFile($fp);
		closeFile($rp);
		safe_unlink($upfile);
		return error($en? 'This file is an unsupported format.':'対応していないフォーマットです。');
	}
	list($w, $h) = getimagesize($upfile);
	$img_md5=md5_file($upfile);
	
	$imgfile = $time.$imgext;

	//チェックするスレッド数。 
	$n= 15;
	$chk_log_arr=array_slice($alllog_arr,0,$n,false);
	$chk_resnos=[];
	foreach($chk_log_arr as $chk_log){
		list($chk_resno)=explode("\t",$chk_log);
		$chk_resnos[]=$chk_resno;
	}
	$chk_lines=[];

	foreach($chk_resnos as $chk_resno){
		if(($chk_resno!==$no)&&is_file(LOG_DIR."{$chk_resno}.log")){
			$cp=fopen(LOG_DIR."{$chk_resno}.log","r");
			while($line=fgets($cp)){
				if(!trim($line)){
					continue;
				}
			$chk_lines[]=$line;//画像
			}
			fclose($cp);
		}
	}
	$chk_images=array_merge($chk_lines,$r_arr);
	foreach($chk_images as $chk_line){
		list($chk_no,$chk_sub,$chk_name,$chk_verified,$chk_com,$chk_url,$chk_imgfile,$chk_w,$chk_h,$chk_thumbnail,$chk_painttime,$chk_log_md5,$chk_tool,$chk_pchext,$chk_time,$chk_first_posted_time,$chk_host,$chk_userid,$chk_hash,$chk_oya_)=explode("\t",trim($chk_line));
		if(((int)$time-(int)$chk_time)<1000){//投稿時刻の重複回避が主目的
			safe_unlink($upfile);
			return error($en? 'Please wait a little.':'少し待ってください。');
		}
		if(($tool==='upload') && $chk_log_md5 && ($chk_log_md5 === $img_md5)){
			safe_unlink($upfile);
			closeFile($fp);
			closeFile($rp);
			return error($en?'Image already exists.':'同じ画像がありました。');
		}
	}
	rename($upfile,IMG_DIR.$imgfile);
	chmod(IMG_DIR.$imgfile,0606);
	$src='';
	//PCHファイルアップロード
	// .pch, .spch,.chi,.psd ブランク どれかが返ってくる
	if ($pchext = check_pch_ext(TEMP_DIR . $file_name,['upload'=>true])) {
		$src = TEMP_DIR . $file_name . $pchext;
		$dst = IMG_DIR . $time . $pchext;
		if(copy($src, $dst)){
			chmod($dst, 0606);
		}
	}
	list($w,$h)=getimagesize(IMG_DIR.$imgfile);

	//縮小表示 
	$max_w = ($_oya==='res') ? $res_max_w : $max_w; 
	$max_h = ($_oya==='res') ? $res_max_h : $max_h; 

	list($w,$h)=image_reduction_display($w,$h,$max_w,$max_h);
	
	//サムネイル
	$thumbnail='';
	if($use_thumb){
		if(thumb(IMG_DIR,$imgfile,$time,$max_w,$max_h)){
			$thumbnail='thumbnail';
		}
	}

	//描画時間追加

	$painttime = '';
	if($starttime && is_numeric($starttime) && $postedtime && is_numeric($postedtime)){
		$psec=(int)$postedtime-(int)$starttime;
		$painttime=(int)$_painttime+(int)$psec;
	}
	
	$new_line= "$_no\t$_sub\t$_name\t$_verified\t$_com\t$_url\t$imgfile\t$w\t$h\t$thumbnail\t$painttime\t$img_md5\t$tool\t$pchext\t$time\t$_first_posted_time\t$host\t$userid\t$_hash\t$_oya\n";

	$r_arr[$i] = $new_line;

	if($_oya ==='oya'){
		$flag=false;
		foreach($alllog_arr as $i => $val){
			list($no_,$sub_,$name_,$verified_,$com_,$url_,$imgfile_,$w_,$h_,$thumbnail_,$painttime_,$log_md5_,$tool_,$pchext_,$time_,$first_posted_time_,$host_,$userid_,$hash_,$oya_) = explode("\t",trim($val));

			if($id===$time_ && $no===$no_ && $pwd && password_verify($pwd,$hash_)){
				$alllog_arr[$i] = $new_line;
				$flag=true;
				break;
			}
		}
		if(!$flag){
			closeFile($rp);
			closeFile($fp);
			safe_unlink(IMG_DIR.$imgfile);
			return error($en?'The operation failed.':'失敗しました。');
		}

		writeFile($fp,implode("",$alllog_arr));

	}
	writeFile($rp, implode("", $r_arr));
	closeFile($rp);
	closeFile($fp);
	
	//旧ファイル削除
	delete_files($_imgfile, $_time);
	//ワークファイル削除
	safe_unlink($src);
	safe_unlink($tempfile);
	safe_unlink($up_tempfile);
	safe_unlink($upfile);
	safe_unlink(TEMP_DIR.$file_name.".dat");

	if($tool==='upload'){
		return edit_form($time,$no);//編集画面にもどる
	}
	unset($_SESSION['userdel']);
	return header('Location: ./?resno='.$no.'#'.$time);

}

// 動画表示
function pchview(){
	global $boardname,$skindir,$en;

	$imagefile = filter_input(INPUT_GET, 'imagefile');
	$pch = pathinfo($imagefile, PATHINFO_FILENAME);
	$pchext = check_pch_ext(IMG_DIR . $pch);
	if(!$pchext){
		return error('ファイルがありません。');
	}
	$pchfile = IMG_DIR.$pch.$pchext;

	list($picw, $pich) = getimagesize(IMG_DIR.$imagefile);
	$appw = $picw < 200 ? 200 : $picw;
	$apph = $pich < 200 ? 200 : $pich + 26;

	// HTML出力
	$templete='pch_view.html';
	return include __DIR__.'/'.$skindir.$templete;

}
//削除前の確認画面
function confirmation_before_deletion ($edit_mode=''){

	global $boardname,$home,$petit_ver,$petit_lot,$skindir,$use_aikotoba,$set_nsfw,$en;
	//管理者判定処理
	session_sta();
	$admindel=admindel_valid();
	$aikotoba=aikotoba_valid();
	$userdel=isset($_SESSION['userdel'])&&($_SESSION['userdel']==='userdel_mode');
	$resmode = filter_input(INPUT_POST,'resmode',FILTER_VALIDATE_BOOLEAN);
	$resmode = $resmode ? 'true' : 'false';
	$postpage = filter_input(INPUT_POST,'postpage',FILTER_VALIDATE_INT);
	$postresno = filter_input(INPUT_POST,'postresno',FILTER_VALIDATE_INT);
	$postpage = ($postpage || $postpage===0) ? $postpage : 0; 
	$postresno = ($postresno) ? $postresno : false; 

	$pwdc=filter_input(INPUT_COOKIE,'pwdc');


	$edit_mode = filter_input(INPUT_POST,'edit_mode');

	if(!($admindel||$userdel)){
		return error($en?'The operation failed.':'失敗しました。');
	}

	if($edit_mode!=='delmode' && $edit_mode!=='editmode'){
		return error($en?'The operation failed.':'失敗しました。');
	}
	$id = t((string)filter_input(INPUT_POST,'id',FILTER_VALIDATE_INT));
	$no = t((string)filter_input(INPUT_POST,'no',FILTER_VALIDATE_INT));

	if(!is_file(LOG_DIR."{$no}.log")){
		return error($en? 'The article does not exist.':'記事がありません。');
	}

	$rp=fopen(LOG_DIR."{$no}.log","r");
	flock($rp, LOCK_EX);
	$r_arr=[];
	while ($r_line = fgets($rp)) {
		if(!trim($r_line)){
			continue;
		}
		$r_arr[]=$r_line;
	}
	if(empty($r_arr)){
		closeFile($rp);
		return error($en?'The operation failed.':'失敗しました。');
	}
	$find=false;
	foreach($r_arr as $i =>$val){
		$_line=explode("\t",trim($val));
		list($_no,$sub,$name,$verified,$com,$url,$imgfile,$w,$h,$thumbnail,$painttime,$log_md5,$tool,$pchext,$time,$first_posted_time,$host,$userid,$hash,$oya)=$_line;
		if($id===$time && $no===$_no){

			$out[0][]=create_res($_line);
			$find=true;
			break;
			
		}

	}
	if(!$find){
		closeFile ($rp);
		return error($en?'The article was not found.':'見つかりませんでした。');
	}

	closeFile ($rp);

	$token=get_csrf_token();

	if(!$use_aikotoba){
		$aikotoba=true;
	}
	// nsfw
	$nsfwc=filter_input(INPUT_COOKIE,'nsfwc',FILTER_VALIDATE_BOOLEAN);

	if($edit_mode==='delmode'){
		$templete='before_del.html';
		return include __DIR__.'/'.$skindir.$templete;
	}
	if($edit_mode==='editmode'){
		$templete='before_edit.html';
		return include __DIR__.'/'.$skindir.$templete;
	}
	return error($en?'The operation failed.':'失敗しました。');
}
//編集画面
function edit_form($id='',$no=''){

	global  $petit_ver,$petit_lot,$home,$boardname,$skindir,$set_nsfw,$en,$max_kb;
	$max_byte = $max_kb * 1024*2;
	$token=get_csrf_token();
	$admindel=admindel_valid();
	$userdel=isset($_SESSION['userdel'])&&($_SESSION['userdel']==='userdel_mode');

	$pwd=(string)filter_input(INPUT_POST,'pwd');
	$pwdc=(string)filter_input(INPUT_COOKIE,'pwdc');
	$pwd = $pwd ? $pwd : $pwdc;
	
	if(!($admindel||($userdel&&$pwd))){
		return error($en?'The operation failed.':'失敗しました。');
	}
	$id_and_no=(string)filter_input(INPUT_POST,'id_and_no');

	if($id_and_no){
		list($id,$no)=explode(",",trim($id_and_no));
	}

	if(!is_file(LOG_DIR."{$no}.log")){
		return error($en? 'The article does not exist.':'記事がありません。');
	}
		
	$rp=fopen(LOG_DIR."{$no}.log","r");
	flock($rp, LOCK_EX);
	$r_arr=[];
	while ($r_line = fgets($rp)) {
		if(!trim($r_line)){
			continue;
		}
		$r_arr[]=$r_line;
	}
	if(empty($r_arr)){
		closeFile($rp);
		return error($en?'The operation failed.':'失敗しました。');
	}

	$flag=false;
	foreach($r_arr as $val){

		$line=explode("\t",trim($val));

		list($_no,$sub,$name,$verified,$com,$url,$imgfile,$w,$h,$thumbnail,$painttime,$log_md5,$tool,$pchext,$time,$first_posted_time,$host,$userid,$hash,$oya)=$line;
		if($id===$time && $no===$_no){
		
			if(!$admindel){

				if(!check_elapsed_days($time)||!$pwd||!password_verify($pwd,$hash)){
					closeFile($rp);
					return error($en?'The operation failed.':'失敗しました。');
				}
			}
			$flag=true;
			break;
		}
	}

	if(!$flag){
		closeFile($rp);
		return error($en?'The article was not found.':'見つかりませんでした。');
	}
	closeFile($rp);

	$out[0][]=create_res($line);//$lineから、情報を取り出す;

	$resno=filter_input(INPUT_POST,'postresno',FILTER_VALIDATE_INT);
	$page=filter_input(INPUT_POST,'postpage',FILTER_VALIDATE_INT);

	foreach($line as $i => $val){
		$line[$i]=h($val);
	}
	list($_no,$sub,$name,$verified,$_com,$url,$imgfile,$w,$h,$thumbnail,$painttime,$log_md5,$tool,$pchext,$time,$first_posted_time,$host,$userid,$hash,$oya)=$line;

	$com=h(str_replace('"\n"',"\n",$com));

	$page = ($page||$page===0) ? $page : false; 
	$resno = $resno ? $resno : false;
	$nsfwc=filter_input(INPUT_COOKIE,'nsfwc',FILTER_VALIDATE_BOOLEAN);

	// HTML出力
	$templete='edit_form.html';
	return include __DIR__.'/'.$skindir.$templete;

}

//編集
function edit(){
	global $name_input_required,$max_com,$en;

	check_csrf_token();

	//POSTされた内容を取得
	$userip =t(get_uip());
	//ホスト取得
	$host = t(gethostbyaddr($userip));
	$userid = t(getId($userip));

	$sub = t((string)filter_input(INPUT_POST,'sub'));
	$name = t((string)filter_input(INPUT_POST,'name'));
	$com = t((string)filter_input(INPUT_POST,'com'));
	$url = t((string)filter_input(INPUT_POST,'url',FILTER_VALIDATE_URL));
	$id = t((string)filter_input(INPUT_POST,'id',FILTER_VALIDATE_INT));
	$no = t((string)filter_input(INPUT_POST,'no',FILTER_VALIDATE_INT));
	
	$pwd=(string)filter_input(INPUT_POST,'pwd');
	$pwdc=(string)filter_input(INPUT_COOKIE,'pwdc');
	$pwd = $pwd ? $pwd : $pwdc;
	session_sta();
	$admindel=admindel_valid();
	$userdel=isset($_SESSION['userdel'])&&($_SESSION['userdel']==='userdel_mode');
	if(!($admindel||($userdel&&$pwd))){
		return error($en?'The operation failed.':'失敗しました。');
	}

	//NGワードがあれば拒絶
	Reject_if_NGword_exists_in_the_post();

	//POSTされた値をログファイルに格納する書式にフォーマット
	$formatted_post=create_formatted_text_from_post($name,$sub,$url,$com);
	$name = $formatted_post['name'];
	$sub = $formatted_post['sub'];
	$url = $formatted_post['url'];
	$com = $formatted_post['com'];

	if(!$name){
		if($name_input_required){
			return error($en?'Please enter your name.':'名前がありません。');
		}else{
			$name='anonymous';
		}
	}
	//ログ読み込み
	if(!is_file(LOG_DIR."{$no}.log")){
		return error($en? 'The article does not exist.':'記事がありません。');
	}
	$fp=fopen(LOG_DIR."alllog.log","r+");
	flock($fp, LOCK_EX);

	$r_arr=[];
	$rp=fopen(LOG_DIR."{$no}.log","r+");
	flock($rp, LOCK_EX);
	while ($line = fgets($rp)) {
		if(!trim($line)){
			continue;
		}
		$r_arr[]=$line;
	}
	if(empty($r_arr)){
		closeFile($rp);
		closeFile($fp);
		return error($en?'The operation failed.':'失敗しました。');
	}

	$flag=false;
	foreach($r_arr as $i => $line){

		list($_no,$_sub,$_name,$_verified,$_com,$_url,$_imgfile,$_w,$_h,$_thumbnail,$_painttime,$_log_md5,$_tool,$_pchext,$_time,$_first_posted_time,$_host,$_userid,$_hash,$_oya)=explode("\t",trim($line));
		if($id===$_time && $no===$_no){

			if(!$admindel){

				if(!check_elapsed_days($_time)||!$pwd||!password_verify($pwd,$_hash)){
					closeFile($rp);
					closeFile($fp);
					return error($en?'The operation failed.':'失敗しました。');
				}
			}
			$flag=true;
			break;
		}
	}
	if(!$flag){
		closeFile($rp);
		closeFile($fp);
		return error($en?'The article was not found.':'見つかりませんでした。');
	}
	if(!$_imgfile && !$com){
		closeFile($rp);
		closeFile($fp);
		return error($en?'Please write something.':'何か書いて下さい。');
	}
	$time = time().substr(microtime(),2,3);

	$sub=($_oya==='res') ? $_sub : $sub; 

	$sub=(!$sub) ? ($en? 'No subject':'無題') : $sub;

	$new_line= "$_no\t$sub\t$name\t$_verified\t$com\t$url\t$_imgfile\t$_w\t$_h\t$_thumbnail\t$_painttime\t$_log_md5\t$_tool\t$_pchext\t$_time\t$_first_posted_time\t$host\t$userid\t$_hash\t$_oya\n";

	$r_arr[$i] = $new_line;

	if($_oya==='oya'){
		$alllog_arr=[];
		while ($_line = fgets($fp)) {
			if(!trim($_line)){
				continue;
			}
			$alllog_arr[]=$_line;	
		}
		if(empty($alllog_arr)){
			closeFile($rp);
			closeFile($fp);
			return error($en?'The operation failed.':'失敗しました。');
		}
		$flag=false;
		foreach($alllog_arr as $i => $val){
			list($no_,$sub_,$name_,$verified_,$com_,$url_,$imgfile_,$w_,$h_,$thumbnail_,$painttime_,$log_md5_,$tool_,$pchext_,$time_,$first_posted_time_,$host_,$userid_,$hash_,$oya_) = explode("\t",trim($val));
			if(($id===$time_ && $no===$no_) &&
			($admindel || ($pwd && password_verify($pwd,$hash_)))){

				$alllog_arr[$i] = $new_line;
				$flag=true;
				break;
			}
		}
		if(!$flag){
			closeFile($rp);
			closeFile($fp);
			return error($en?'The operation failed.':'失敗しました。');
		}

		writeFile($fp,implode("",$alllog_arr));
	}
	writeFile($rp, implode("", $r_arr));

	closeFile($rp);
	closeFile($fp);

	unset($_SESSION['userdel']);

	return header('Location: ./?resno='.$no.'#'.$_time);

}

//記事削除
function del(){
	global $en;
	$pwd=(string)filter_input(INPUT_POST,'pwd');
	$pwdc=(string)filter_input(INPUT_COOKIE,'pwdc');
	$pwd = $pwd ? $pwd : $pwdc;
	check_csrf_token();
	session_sta();
	$admindel=admindel_valid();
	$userdel_mode=isset($_SESSION['userdel'])&&($_SESSION['userdel']==='userdel_mode');
	if(!($admindel||($userdel_mode&&$pwd))){
		return error($en?'The operation failed.':'失敗しました。');
	}
	$id_and_no=(string)filter_input(INPUT_POST,'id_and_no');
	if(!$id_and_no){
		return error($en?'Please select an article.':'記事が選択されていません。');
	}
	$id=$no='';
	if($id_and_no){
		list($id,$no)=explode(",",trim($id_and_no));
	}
	$fp=fopen(LOG_DIR."alllog.log","r+");
	flock($fp, LOCK_EX);

	if(!is_file(LOG_DIR."{$no}.log")){
		return error($en? 'The article does not exist.':'記事がありません。');
	}
	$rp=fopen(LOG_DIR."{$no}.log","r+");
	flock($rp, LOCK_EX);
	$r_arr=[];
	while ($r_line = fgets($rp)) {
		if(!trim($r_line)){
			continue;
		}
		$r_arr[]=$r_line;
	}
	if(empty($r_arr)){
		closeFile ($rp);
		closeFile($fp);
		return error($en?'The operation failed.':'失敗しました。');
	}

	$find=false;
	foreach($r_arr as $i =>$val){
		list($_no,$sub,$name,$verified,$com,$url,$imgfile,$w,$h,$thumbnail,$painttime,$log_md5,$tool,$pchext,$time,$first_posted_time,$host,$userid,$hash,$oya)=explode("\t",trim($val));
		if($id===$time && $no===$_no){
		
			if(!$admindel){
				if(!$pwd||!password_verify($pwd,$hash)){
					closeFile ($rp);
					closeFile($fp);
					return error($en?'The operation failed.':'失敗しました。');
				}
			}
			if($oya==='oya'){//スレッド削除
				$alllog_arr=[];
				while ($_line = fgets($fp)) {
					if(!trim($_line)){
						continue;
					}
					$alllog_arr[]=$_line;	
				}
				if(empty($alllog_arr)){
					closeFile ($rp);
					closeFile($fp);
					return error($en?'The operation failed.':'失敗しました。');
				}
				$flag=false;
				foreach($alllog_arr as $i =>$_val){//全体ログ
					list($no_,$sub_,$name_,$verified_,$com_,$url_,$_imgfile_,$w_,$h_,$thumbnail_,$painttime_,$log_md5_,$tool_,$pchext_,$time_,$first_posted_time_,$host_,$userid_,$hash_,$oya_)=explode("\t",trim($_val));
					if(($id===$time_ && $no===$no_) &&
					($admindel || ($pwd && password_verify($pwd,$hash_)))){

						unset($alllog_arr[$i]);
						$flag=true;
						break;
					}
				}
				if(!$flag){
					closeFile ($rp);
					closeFile($fp);
					return error($en?'The operation failed.':'失敗しました。');
				}

				foreach($r_arr as $r_line) {//レスファイル
					list($_no,$_sub,$_name,$_verified,$_com,$_url,$_imgfile,$_w,$_h,$_thumbnail,$_painttime,$_log_md5,$_tool,$_pchext,$_time,$_first_posted_time,$_host,$_userid,$_hash,$_oya)=explode("\t",trim($r_line));
					
					delete_files ($_imgfile, $_time);//一連のファイルを削除
					
				}
				writeFile($fp,implode("",$alllog_arr));
				safe_unlink(LOG_DIR.$no.'.log');
		
			}else{

				unset($r_arr[$i]);
				delete_files ($imgfile, $time);//一連のファイルを削除
				writeFile ($rp,implode("",$r_arr));
			}
			$find=true;
			break;
		}
	}
	closeFile ($rp);
	closeFile($fp);

	if(!$find){
		return error($en?'The article was not found.':'見つかりませんでした。');
	}

	unset($_SESSION['userdel']);
	$resno=(string)filter_input(INPUT_POST,'postresno');
	//多重送信防止
	if(filter_input(INPUT_POST,'resmode')==='true'){
		if(!is_file(LOG_DIR.$resno.'.log')){
			return header('Location: ./');
		}
		return header('Location: ./?resno='.$resno);
	}
	return header('Location: ./?page='.filter_input(INPUT_POST,'postpage'));
}

//カタログ表示
function catalog($page=0,$q=''){
	global $use_aikotoba,$home,$catalog_pagedef,$skindir;
	global $boardname,$petit_ver,$petit_lot,$set_nsfw,$en; 
	$pagedef=$catalog_pagedef;
	
	$q=filter_input(INPUT_GET,'q');

	$fp=fopen(LOG_DIR."alllog.log","r");
	$alllog_arr=[];
	while ($_line = fgets($fp)) {
		if(!trim($_line)){
			continue;
		}
		$alllog_arr[]=$_line;	
	}
	fclose($fp);

	$encoded_q='';
	$result=[];
	$j=0;
	if($q){//名前検索の時
		foreach($alllog_arr as $i => $alllog){
			if(!trim($alllog)){
				continue;
			}
			list($no,)=explode("\t",trim($alllog));

			//個別スレッドのループ
			if(!is_file(LOG_DIR."{$no}.log")){
				continue;	
			}
			$cp=fopen('log/'."{$no}.log","r");
			while($r_line=fgets($cp)){
				if(!trim($r_line)){
					continue;
				}
				list($no,$sub,$name,$verified,$com,$url,$imgfile,$w,$h,$thumbnail,$painttime,$log_md5,$tool,$pchext,$time,$first_posted_time,$host,$userid,$hash,$oya)=explode("\t",trim($r_line));
				if ($imgfile&&$name===$q){
					$result[$time]=[$no,$sub,$name,$verified,$com,$url,$imgfile,$w,$h,$thumbnail,$painttime,$log_md5,$tool,$pchext,$time,$first_posted_time,$host,$userid,$hash,$oya];
					++$j;
					if($j>120){
						break 2;
					}
				}
			}
			fclose($cp);	
			if($i>300){
				break;
			}
		}
		krsort($result);
		$alllog_arr=$result;
		$encoded_q=urlencode($q);
	}
	$count_alllog=count($alllog_arr);

	//ページ番号から1ページ分のスレッド分とりだす
	$articles=array_slice($alllog_arr,(int)$page,$pagedef,false);
	//oyaのループ
	foreach($articles as $oya => $line){
		$out[$oya]=[];
		if(!$q){//検索結果は分割ずみ
			$line=explode("\t",trim($line));
		}
		list($_no)=$line;
		if(!is_file(LOG_DIR."{$_no}.log")){
		continue;
		}	
		$out[$oya][] = create_res($line);//$lineから、情報を取り出す
		if(empty($out[$oya])||$out[$oya][0]['oya']!=='oya'){
			unset($out[$oya]);
		}

	}

	//管理者判定処理
	session_sta();
	$admindel=admindel_valid();
	$aikotoba=aikotoba_valid();
	$userdel=isset($_SESSION['userdel'])&&($_SESSION['userdel']==='userdel_mode');
	$adminpost=adminpost_valid();

	if(!$use_aikotoba){
		$aikotoba=true;
	}

	//Cookie
	$nsfwc=filter_input(INPUT_COOKIE,'nsfwc',FILTER_VALIDATE_BOOLEAN);
	//token
	$token=get_csrf_token();

	//ページング
	$start_page=$page-$pagedef*8;
	if($page<$pagedef*17){
		$start_page=0;
	}
	$end_page=$page+($pagedef*8)	;
	if($page<$pagedef*17){
		$end_page=$pagedef*17;
	}
	//prev next 
	$next=(($page+$pagedef)<$count_alllog) ? $page+$pagedef : false;//ページ番号がmaxを超える時はnextのリンクを出さない
	$prev=((int)$page!==0) ? ($page-$pagedef) : false;//ページ番号が0の時はprevのリンクを出さない

	// HTML出力
	$templete='catalog.html';
	return include __DIR__.'/'.$skindir.$templete;

}

//通常表示
function view($page=0){
	global $use_aikotoba,$use_upload,$home,$pagedef,$dispres,$allow_coments_only,$use_top_form,$skindir,$descriptions,$max_kb;
	global $boardname,$max_res,$pmax_w,$pmax_h,$use_miniform,$use_diary,$petit_ver,$petit_lot,$set_nsfw,$use_sns_button,$deny_all_posts,$en; 
	$max_byte = $max_kb * 1024*2;
	$denny_all_posts=$deny_all_posts;//互換性

	$fp=fopen(LOG_DIR."alllog.log","r");
	$alllog_arr=[];
	while ($_line = fgets($fp)) {
		if(!trim($_line)){
			continue;
		}
		$alllog_arr[]=$_line;	
	}
	fclose($fp);
	$count_alllog=count($alllog_arr);


	//ページ番号から1ページ分のスレッドをとりだす
	$articles=array_slice($alllog_arr,(int)$page,$pagedef,false);
	//oyaのループ
	foreach($articles as $oya => $alllog){

		list($no)=explode("\t",trim($alllog));
		//個別スレッドのループ
		if(!is_file(LOG_DIR."{$no}.log")){
			continue;	
		}
		$_res=[];
		$out[$oya]=[];
			$rp = fopen(LOG_DIR."{$no}.log", "r");//個別スレッドのログを開く
			$s=0;
			while ($line = fgets($rp)) {
				if(!trim($line)){
					continue;
				}
				$_res = create_res(explode("\t",trim($line)));//$lineから、情報を取り出す
				$out[$oya][]=$_res;
			}	
		fclose($rp);
		if(empty($out[$oya])||$out[$oya][0]['oya']!=='oya'){
			unset($out[$oya]);
		}
	}

	//管理者判定処理
	session_sta();
	$admindel=admindel_valid();
	$aikotoba=aikotoba_valid();
	$userdel=isset($_SESSION['userdel'])&&($_SESSION['userdel']==='userdel_mode');
	$adminpost=adminpost_valid();

	if(!$use_aikotoba){
		$aikotoba=true;
	}

	//Cookie
	$namec=h((string)filter_input(INPUT_COOKIE,'namec'));
	$pwdc=h((string)filter_input(INPUT_COOKIE,'pwdc'));
	$urlc=h((string)filter_input(INPUT_COOKIE,'urlc'));
	$appc=h((string)filter_input(INPUT_COOKIE,'appc'));
	$picwc=h((string)filter_input(INPUT_COOKIE,'picwc'));
	$pichc=h((string)filter_input(INPUT_COOKIE,'pichc'));
	$nsfwc=filter_input(INPUT_COOKIE,'nsfwc',FILTER_VALIDATE_BOOLEAN);

	//token
	$token=get_csrf_token();

	//ページング
	$start_page=$page-$pagedef*8;
	if($page<$pagedef*17){
		$start_page=0;
	}
	$end_page=$page+($pagedef*8);
	if($page<$pagedef*17){
		$end_page=$pagedef*17;
	}
	//prev next 
	$next=(($page+$pagedef)<$count_alllog) ? $page+$pagedef : false;//ページ番号がmaxを超える時はnextのリンクを出さない
	$prev=((int)$page!==0) ? ($page-$pagedef) : false;//ページ番号が0の時はprevのリンクを出さない

	// HTML出力
	$templete='main.html';
	return include __DIR__.'/'.$skindir.$templete;

}
//レス画面
function res ($resno){
	global $use_aikotoba,$use_upload,$home,$skindir,$root_url,$use_res_upload,$max_kb;
	global $boardname,$max_res,$pmax_w,$pmax_h,$petit_ver,$petit_lot,$set_nsfw,$use_sns_button,$deny_all_posts,$sage_all,$view_other_works,$en;
	$max_byte = $max_kb * 1024*2;

	$denny_all_posts=$deny_all_posts;
	$page='';
	$resno=filter_input(INPUT_GET,'resno');
	if(!is_file(LOG_DIR."{$resno}.log")){
		return error($en?'Thread does not exist.':'スレッドがありません');	
		}
		$rresname = [];
		$resname = '';
		$oyaname='';
			$rp = fopen(LOG_DIR."{$resno}.log", "r");//個別スレッドのログを開く
			$out[0]=[];
			while ($line = fgets($rp)) {
				if(!trim($line)){
					continue;
				}
				$_res = create_res(explode("\t",trim($line)));//$lineから、情報を取り出す

				if($_res['oya']==='oya'){
					$oyaname = $_res['name'];
				} 
				// 投稿者名を配列にいれる
					if (($oyaname !== $_res['name']) && !in_array($_res['name'], $rresname)) { // 重複チェックと親投稿者除外
						$rresname[] = $_res['name'];
					}
			$out[0][]=$_res;
			}	
		fclose($rp);
		if(empty($out[0])||$out[0][0]['oya']!=='oya'){
			return error($en? 'The article does not exist.':'記事がありません。');
		}
		//投稿者名の特殊文字を全角に
		foreach($rresname as $key => $val){
			$rep=str_replace('&quot;','”',$val);
			$rep=str_replace('&#039;','’',$rep);
			$rep=str_replace('&lt;','＜',$rep);
			$rep=str_replace('&gt;','＞',$rep);
			$rresname[$key]=str_replace('&amp;','＆',$rep);
		}			
	
		$resname = !empty($rresname) ? implode(($en?'-san':'さん').' ',$rresname) : false; // レス投稿者一覧

		$fp=fopen(LOG_DIR."alllog.log","r");
		$articles=[];
		while ($line = fgets($fp)) {
			if(!trim($line)){
				continue;
			}
			$articles[] = $line;//$_lineから、情報を取り出す
		}
		fclose($fp);
		$i=0;
		foreach($articles as $i =>$article){//現在のスレッドのキーを取得
			if (strpos(trim($article), $resno . "\t") === 0) {
				break;
			}
		}
		$next=isset($articles[$i+1])? rtrim($articles[$i+1]) :'';
		$prev=isset($articles[$i-1])? rtrim($articles[$i-1]) :'';
		$next=$next ? (create_res(explode("\t",trim($next)))):[];
		$prev=$prev ? (create_res(explode("\t",trim($prev)))):[];
		$next=(!empty($next) && is_file(LOG_DIR."{$next['no']}.log"))?$next:[];
		$prev=(!empty($prev) && is_file(LOG_DIR."{$prev['no']}.log"))?$prev:[];

		if($view_other_works){
			$view_other_works=[];
			$a=[];
			$start_view=(($i-7)>=0) ? ($i-7) : 0;
			$other_articles=array_slice($articles,$start_view,17,false);
			foreach($other_articles as $val){
				$b=create_res(explode("\t",trim($val)));
				if(!empty($b)&&$b['img']&&$b['no']!==$resno){
					$a[]=$b;
				}
			}
			$c=($i<5) ? 0 : (count($a)>9 ? 4 :0);
			$view_other_works=array_slice($a,$c,6,false);
		}
	//管理者判定処理
	session_sta();
	$admindel=admindel_valid();
	$aikotoba=aikotoba_valid();
	$userdel=isset($_SESSION['userdel'])&&($_SESSION['userdel']==='userdel_mode');
	$adminpost=adminpost_valid();
	if(!$use_aikotoba){
		$aikotoba=true;
	}

	//Cookie
	$namec=h((string)filter_input(INPUT_COOKIE,'namec'));
	$pwdc=h((string)filter_input(INPUT_COOKIE,'pwdc'));
	$urlc=h((string)filter_input(INPUT_COOKIE,'urlc'));
	$appc=h((string)filter_input(INPUT_COOKIE,'appc'));
	$picwc=h((string)filter_input(INPUT_COOKIE,'picwc'));
	$pichc=h((string)filter_input(INPUT_COOKIE,'pichc'));
	$nsfwc=filter_input(INPUT_COOKIE,'nsfwc',FILTER_VALIDATE_BOOLEAN);

	//token
	$token=get_csrf_token();
	$templete='res.html';
	return include __DIR__.'/'.$skindir.$templete;
	
}
