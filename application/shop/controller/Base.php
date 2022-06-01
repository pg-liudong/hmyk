<?php

namespace app\shop\controller;
use app\common\controller\Mask;
use fast\Random;
use think\Config;
use think\Controller;
use think\Db;
use think\Session;
use think\Cache;
use think\Request;
use think\Response;
use think\exception\HttpResponseException;

class Base extends Controller {

    /**
     * 默认响应输出类型,支持json/xml
     * @var string
     */
    protected $responseType = 'json';

    protected $timestamp = 0;


    protected $user = null; //用户信息

    protected $station_id = 0;  //子站id

    protected $site = []; //系统配置信息

    protected $domain = ""; //域名

    protected $equipment = "pc"; //客户端设备

    protected $template_path = ""; //模板路径

    protected $template_version = 0; //模板版本

    protected $avatar = "/uploads/20210106/634991592083b770187ab213c25d022a.jpg"; //默认头像

//    protected $template_name = "dujiao"; //模板名称
    protected $template_name = "default"; //模板名称

    protected $options = [];


    public function _initialize() {

        parent::_initialize(); // TODO: Change the autogenerated stub



        $this->site = Config::get("site");

        if(session::has('user')){
            $field = "u.id, u.secret, u.consume, u.nickname, u.password, u.salt, u.email, u.mobile, u.avatar, u.agent, u.money,";
            $field .= "u.score, u.createtime, g.name grade_name, g.discount";
            $this->user = db::name('user')->alias('u')
                ->join('user_grade g', 'u.agent=g.id', 'left')
                ->field($field)
                ->where(['u.id' => session::get('user')['id']])->find();
        }else{
            $this->user = null;
        }



        $controller = strtolower($this->request->controller());
        /*if($controller != 'login' && $controller != 'register' && $this->site['force_login'] == 1 && $this->user == null){
            $this->redirect('/login');
        }*/

        $plugin_data = [
            'controller' => $controller,
            'user' => $this->user
        ];


        $this->timestamp = time();

        $this->equipment = is_mobile() ? 'mobile' : 'pc';


        $options = db::name('options')->select();

        foreach($options as $val){
            $this->options[$val['option_name']] = $val['option_content'];
        }

        if(!empty($this->options['active_template'])){
            $active_template = unserialize($this->options['active_template']);
            $this->template_name = $active_template[$this->equipment];
        }

        $template_info_path = ROOT_PATH . 'public/content/template/' . $this->template_name . '/info.php';
        if(file_exists($template_info_path)){
            $templateData = include_once($template_info_path);
        }else{
            die("【{$this->template_name}】模板文件不存在");
        }


        $this->template_version = $templateData['version'];

        if(isset($_SERVER['REQUEST_SCHEME'])){
            $this->domain = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . '/';
        }else{
            $this->domain = 'http://' . $_SERVER['HTTP_HOST'] . '/';
        }


        $this->template_path = ROOT_PATH . 'public/content/template/' . $this->template_name . '/';

        include_once $this->template_path . "module.php";


        $template_config = file_get_contents(ROOT_PATH . "public/content/template/{$this->template_name}/setting.json"); //模板配置
        $template_config = json_decode($template_config, true);


        $this->site['eject_goods'] = empty(strip_tags($this->site['eject_goods'])) ? null : $this->site['eject_goods'];

        $this->assign([
            'site' => $this->site,
            'user' => $this->user,
            "template_version" => $this->template_version,
            'template' => $template_config,
            'options' => $this->options,
            'navi' => '',
        ]);



        $active_plugins = Db::name('options')->where(['option_name' => 'active_plugin'])->value('option_content');
        $active_plugins = empty($active_plugins) ? [] : unserialize($active_plugins);
        if ($active_plugins && is_array($active_plugins)) {
            foreach($active_plugins as $plugin) {
                if(true === checkPlugin($plugin) && substr($plugin, -13) != '_template.php' && substr($plugin, -8) != '_pay.php') {
                    include_once(ROOT_PATH . 'public/content/plugin/' . $plugin);
                }
            }
        }

        doAction('base_controller', $plugin_data);

    }

    public function errorPage($msg, $description='', $title='配置错误', $code=500){
        $this->assign([
            'msg' => $msg,
            'description' => $description,
            'code' => $code,
            'title' => $title,
        ]);
        return view('error/index');
    }




    /**
     * 处理商品信息
     */
    public function handle_goods($goods){
        foreach($goods as $key => &$val){
            $images = explode(',', $val['images']);
            $val['cover'] = $images[0];
        }
        return $goods;
    }







    //生成订单号
    public function generateOrderNo(){
        $order_no = date('YmdHis', time()) . mt_rand(1000, 9999);
        return $order_no;
    }


    /**
     * 获取密码加密后的字符串
     * @param string $password 密码
     * @param string $salt 密码盐
     * @return string
     */
    public function getEncryptPassword($password, $salt = '') {
        return md5(md5($password) . $salt);
    }



    /**
     * 操作成功返回的数据
     * @param string $msg 提示信息
     * @param mixed $data 要返回的数据
     * @param int $code 错误码，默认为1
     * @param string $type 输出类型
     * @param array $header 发送的 Header 信息
     */
    protected function success($msg = '', $data = null, $code = 1, $type = null, array $header = []) {
        $this->result($msg, $data, $code, $type, $header);
    }

    /**
     * 操作失败返回的数据
     * @param string $msg 提示信息
     * @param mixed $data 要返回的数据
     * @param int $code 错误码，默认为0
     * @param string $type 输出类型
     * @param array $header 发送的 Header 信息
     */
    /*    protected function error($msg = '', $data = null, $code = 0, $type = null, array $header = []) {
            $this->result($msg, $data, $code, $type, $header);
        }*/


    /**
     * 返回封装后的 API 数据到客户端
     * @access protected
     * @param mixed $msg 提示信息
     * @param mixed $data 要返回的数据
     * @param int $code 错误码，默认为0
     * @param string $type 输出类型，支持json/xml/jsonp
     * @param array $header 发送的 Header 信息
     * @return void
     * @throws HttpResponseException
     */
    protected function result($msg, $data = null, $code = 0, $type = null, array $header = []) {
        $result = [
            'code' => $code, 'msg' => $msg, 'time' => Request::instance()->server('REQUEST_TIME'), 'data' => $data,
        ];
        // 如果未设置类型则自动判断
        $type = $type ? $type : ($this->request->param(config('var_jsonp_handler')) ? 'jsonp' : $this->responseType);

        if (isset($header['statuscode'])) {
            $code = $header['statuscode'];
            unset($header['statuscode']);
        } else {
            //未设置状态码,根据code值判断
            $code = $code >= 1000 || $code < 200 ? 200 : $code;
        }
        $response = Response::create($result, $type, $code)->header($header);
        throw new HttpResponseException($response);
    }


    /**
     * 上传文件
     * @ApiMethod (POST)
     * @param File $file 文件流
     */
    public function upload(){

        $file = $this->request->file('file');
        if (empty($file)) {
            $this->error(__('No file upload or server upload limit exceeded'));
        }

        //判断是否已经存在附件
        $sha1 = $file->hash();

        $upload = Config::get('upload');

        preg_match('/(\d+)(\w+)/', $upload['maxsize'], $matches);
        $type = strtolower($matches[2]);
        $typeDict = ['b' => 0, 'k' => 1, 'kb' => 1, 'm' => 2, 'mb' => 2, 'gb' => 3, 'g' => 3];
        $size = (int)$upload['maxsize'] * pow(1024, isset($typeDict[$type]) ? $typeDict[$type] : 0);
        $fileInfo = $file->getInfo();
        $suffix = strtolower(pathinfo($fileInfo['name'], PATHINFO_EXTENSION));
        $suffix = $suffix && preg_match("/^[a-zA-Z0-9]+$/", $suffix) ? $suffix : 'file';

        $mimetypeArr = explode(',', strtolower($upload['mimetype']));
        $typeArr = explode('/', $fileInfo['type']);

        //禁止上传PHP和HTML文件
        if (in_array($fileInfo['type'], ['text/x-php', 'text/html']) || in_array($suffix, ['php', 'html', 'htm'])) {
            $this->error(__('Uploaded file format is limited'));
        }
        //验证文件后缀
        if ($upload['mimetype'] !== '*' &&
            (
                !in_array($suffix, $mimetypeArr)
                || (stripos($typeArr[0] . '/', $upload['mimetype']) !== false && (!in_array($fileInfo['type'], $mimetypeArr) && !in_array($typeArr[0] . '/*', $mimetypeArr)))
            )
        ) {
            $this->error(__('Uploaded file format is limited'));
        }
        //验证是否为图片文件
        $imagewidth = $imageheight = 0;
        if (in_array($fileInfo['type'], ['image/gif', 'image/jpg', 'image/jpeg', 'image/bmp', 'image/png', 'image/webp']) || in_array($suffix, ['gif', 'jpg', 'jpeg', 'bmp', 'png', 'webp'])) {
            $imgInfo = getimagesize($fileInfo['tmp_name']);
            if (!$imgInfo || !isset($imgInfo[0]) || !isset($imgInfo[1])) {
                $this->error(__('Uploaded file is not a valid image'));
            }
            $imagewidth = isset($imgInfo[0]) ? $imgInfo[0] : $imagewidth;
            $imageheight = isset($imgInfo[1]) ? $imgInfo[1] : $imageheight;
        }
        $replaceArr = [
            '{year}'     => date("Y"),
            '{mon}'      => date("m"),
            '{day}'      => date("d"),
            '{hour}'     => date("H"),
            '{min}'      => date("i"),
            '{sec}'      => date("s"),
            '{random}'   => Random::alnum(16),
            '{random32}' => Random::alnum(32),
            '{filename}' => $suffix ? substr($fileInfo['name'], 0, strripos($fileInfo['name'], '.')) : $fileInfo['name'],
            '{suffix}'   => $suffix,
            '{.suffix}'  => $suffix ? '.' . $suffix : '',
            '{filemd5}'  => md5_file($fileInfo['tmp_name']),
        ];
        $savekey = $upload['savekey'];
        $savekey = str_replace(array_keys($replaceArr), array_values($replaceArr), $savekey);

        $uploadDir = substr($savekey, 0, strripos($savekey, '/') + 1);
        $fileName = substr($savekey, strripos($savekey, '/') + 1);
        //
        $splInfo = $file->validate(['size' => $size])->move(ROOT_PATH . '/public' . $uploadDir, $fileName);
        if ($splInfo) {
            $params = array(
                'admin_id'    => 0,
                'user_id'     => (int)$this->uid,
                'filesize'    => $fileInfo['size'],
                'imagewidth'  => $imagewidth,
                'imageheight' => $imageheight,
                'imagetype'   => $suffix,
                'imageframes' => 0,
                'mimetype'    => $fileInfo['type'],
                'url'         => $uploadDir . $splInfo->getSaveName(),
                'uploadtime'  => time(),
                'storage'     => 'local',
                'sha1'        => $sha1,
            );
            $attachment = model("attachment");
            $attachment->data(array_filter($params));
            $attachment->save();
            \think\Hook::listen("upload_after", $attachment);
            return ['url' => $uploadDir . $splInfo->getSaveName(), 'code' => 1];
        } else {
            // 上传失败获取错误信息
            return ['msg' => $file->getError(), 'code' => 0];
        }
    }

}
