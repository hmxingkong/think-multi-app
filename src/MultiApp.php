<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2019 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
declare (strict_types = 1);

namespace think\app;

use Closure;
use hmxingkong\utils\code\MCode;
use hmxingkong\utils\file\MDir;
use think\exception\HttpException;
use think\App;
use think\facade\Route;
use think\Request;
use think\Response;

/**
 * 多应用模式支持
 */
class MultiApp
{

    /** @var App */
    protected $app;

    /**
     * 应用名称
     * @var string
     */
    protected $name;

    /**
     * 应用名称
     * @var string
     */
    protected $appName;

    /**
     * 应用路径
     * @var string
     */
    protected $path;

    public function __construct(App $app)
    {
        $this->app  = $app;
        $this->name = $this->app->http->getName();
        $this->path = $this->app->http->getPath();
    }

    /**
     * 多应用解析
     * @access public
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle($request, Closure $next)
    {
        if (!$this->parseMultiApp()) {
            return $next($request);
        }

        return $this->app->middleware->pipeline('app')
            ->send($request)
            ->then(function ($request) use ($next) {
                return $next($request);
            });
    }

    /**
     * 获取路由目录
     * @access protected
     * @return string
     */
    protected function getRoutePath(): string
    {
        //return $this->app->getAppPath() . 'route' . DIRECTORY_SEPARATOR;
        // 应用目录
        $routePath = $this->app->getAppPath() . 'route' . DIRECTORY_SEPARATOR;
        if(is_dir($routePath)){
            return $routePath;
        }
        // 全局目录下对应的应用目录
        $routePath = $this->app->getRootPath() . 'route' . DIRECTORY_SEPARATOR . $this->appName . DIRECTORY_SEPARATOR;
        if(is_dir($routePath)){
            return $routePath;
        }
        // 全局目录
        return $this->app->getRootPath() . 'route' . DIRECTORY_SEPARATOR;
    }

    /**
     * 解析多应用
     * @return bool
     */
    protected function parseMultiApp(): bool
    {
        $scriptName = $this->getScriptName();
        $defaultApp = $this->app->config->get('app.default_app') ?: 'index';

        if ($this->name || ($scriptName && !in_array($scriptName, ['index', 'router', 'think']))) {
            $appName = $this->name ?: $scriptName;
            $this->app->http->setBind();
        } else {
            // 自动多应用识别
            $this->app->http->setBind(false);
            $appName       = null;
            $this->appName = '';

            $bind = $this->app->config->get('app.domain_bind', []);

            if (!empty($bind)) {
                // 获取当前子域名
                $subDomain = $this->app->request->subDomain();
                $domain    = $this->app->request->host(true);

                if (isset($bind[$domain])) {
                    $appName = $bind[$domain];
                    $this->app->http->setBind();
                } elseif (isset($bind[$subDomain])) {
                    $appName = $bind[$subDomain];
                    $this->app->http->setBind();
                } elseif (isset($bind['*'])) {
                    $appName = $bind['*'];
                    $this->app->http->setBind();
                }
            }

            if (!$this->app->http->isBind()) {
                $path = $this->app->request->pathinfo();
                $map  = $this->app->config->get('app.app_map', []);
                $deny = $this->app->config->get('app.deny_app_list', []);
                $name = current(explode('/', $path));

                if (strpos($name, '.')) {
                    $name = strstr($name, '.', true);
                }

                if (isset($map[$name])) {
                    if ($map[$name] instanceof Closure) {
                        $result  = call_user_func_array($map[$name], [$this->app]);
                        $appName = $result ?: $name;
                    } else {
                        $appName = $map[$name];
                    }
                } elseif ($name && (false !== array_search($name, $map) || in_array($name, $deny))) {
                    throw new HttpException(404, 'app not exists:' . $name);
                } elseif ($name && isset($map['*'])) {
                    $appName = $map['*'];
                } else {
                    $appName = $name ?: $defaultApp;
                    $appPath = $this->path ?: $this->app->getBasePath() . $appName . DIRECTORY_SEPARATOR;

                    if (!is_dir($appPath)) {

                        if($appNameF = $this->parseNameByRouteCnf()){
                            $this->setApp($appNameF);
                            return true;
                        }
                        else{
                            $appNameM = 'miss';
                            $appPath = $this->app->getBasePath() . $appNameM . DIRECTORY_SEPARATOR;
                            if(is_dir($appPath)){
                                $this->setApp($appNameM);
                                return true;
                            }
                        }

                        $express = $this->app->config->get('app.app_express', false);
                        if ($express) {
                            $this->setApp($defaultApp);
                            return true;
                        } else {
                            return false;
                        }
                    }
                }

                if ($name) {
                    $this->app->request->setRoot('/' . $name);
                    $this->app->request->setPathinfo(strpos($path, '/') ? ltrim(strstr($path, '/'), '/') : '');
                }
            }
        }

        $this->setApp($appName ?: $defaultApp);
        return true;
    }

    /**
     * 获取当前运行入口名称
     * @access protected
     * @codeCoverageIgnore
     * @return string
     */
    protected function getScriptName(): string
    {
        if (isset($_SERVER['SCRIPT_FILENAME'])) {
            $file = $_SERVER['SCRIPT_FILENAME'];
        } elseif (isset($_SERVER['argv'][0])) {
            $file = realpath($_SERVER['argv'][0]);
        }

        return isset($file) ? pathinfo($file, PATHINFO_FILENAME) : '';
    }

    /**
     * 尝试根据路由配置文件确定应用
     * @return string
     */
    protected function parseNameByRouteCnf(): string
    {
        //获取rule  匹配  /xxxxx
        $simpleRule = $_SERVER['REQUEST_URI'];
        foreach(explode('/', $simpleRule) as $tRule){
            if(!in_array($tRule, ['', '/'])){
                break;
            }
        }

        //应用目录
        $searchPath = $this->app->getBasePath();
        $appPaths = MDir::listFiles($searchPath, $pattern = '*', $type=MDir::TYPE_DIR, $recursive=false, $callback=null);
        foreach($appPaths as $appPath){
            $appRoutePath = $appPath . DIRECTORY_SEPARATOR . 'route';
            if($appName = $this->searchPathForAppName($appRoutePath, $tRule)){
                return $appName;
            }
        }

        //全局目录的应用目录
        $searchPath = $this->app->getRootPath() . DIRECTORY_SEPARATOR . 'route';

        $appPaths = MDir::listFiles($searchPath, $pattern = '*', $type=MDir::TYPE_DIR, $recursive=false, $callback=null);
        foreach($appPaths as $appPath){
            if($appName = $this->searchPathForAppName($appPath, $tRule)){
                return $appName;
            }
        }

        //全局目录
        if($appName = $this->searchPathForAppName($searchPath, $tRule)){
            return $appName;
        }
        return '';
    }

    /**
     * 扫描指定目录下的路由文件
     * @param $appRoutePath
     * @param $tRule
     * @return string
     */
    private function searchPathForAppName($appRoutePath, $tRule)
    {
        if(!is_dir($appRoutePath)){
            return '';
        }
        $appRouteFiles = MDir::listFiles($appRoutePath, $pattern = '*.php', $type=MDir::TYPE_FILE, $recursive=false, $callback=null);
        foreach($appRouteFiles as $appRouteFile){
            if($appName = $this->parseIfThisMatchAppName($appRouteFile, $tRule)){
                return $appName;
            }
        }
        return '';
    }

    /**
     * 根据路由文件检测应用是否匹配
     * @param $appRouteFile
     * @param $tRule
     * @return string
     */
    private function parseIfThisMatchAppName($appRouteFile, $tRule): string
    {
        if(!is_file($appRouteFile) || is_dir($appRouteFile)){
            return '';
        }

        $content = file_get_contents($appRouteFile);
        $content = MCode::removeComment($content);

        //优先读取 rule匹配的 route 内容，识别  /app/controller/method | app/controller/method 中的app字段
        preg_match_all("/rule\s*\(\s*(('\/?".$tRule."')|(\"\/?".$tRule."\"))\s*,\s*(('([^']*)')|\"([^\"]*)\")/i", $content, $matches);
        //不管匹配多少个，取第一个，暂不兼容总路由配置不同prefix的group中有相同的rule
        if(isset($matches[6]) && isset($matches[6][0]) && !empty($matches[6][0])){
            $tParts = explode('/', $matches[6][0]);
            if($tParts[0] == '' && count($tParts) == 4){
                return $tParts[1];
            }
            else if($tParts[0] != '' && count($tParts) == 3){
                return $tParts[0];
            }
        }
        unset($matches);

        //在group里，读取prefix内容
        preg_match_all("/group[\s\S]*?rule\s*\(\s*(('\/?".$tRule."')|(\"\/?".$tRule."\"))\s*,\s*(('[^']*')|(\"[^\"]*\"))[\s\S]*?prefix\s*\((('([^']*)')|(\"([^\"]*)\"))/i", $content, $matches);
        //不管匹配多少个，取第一个，暂不兼容总路由配置不同prefix的group中有相同的rule
        if(isset($matches[9]) && isset($matches[9][0]) && !empty($matches[9][0])){
            $tParts = explode('/', $matches[9][0]);
            if($tParts[0] == '' && count($tParts) >= 2){
                return $tParts[1];
            }
            else if($tParts[0] != '' && count($tParts) >= 1){
                return $tParts[0];
            }
        }

        return '';
    }

    /**
     * 设置应用
     * @param string $appName
     */
    protected function setApp(string $appName): void
    {
        //$appName = 'mapi';
        $this->appName = $appName;
        $this->app->http->name($appName);

        $appPath = $this->path ?: $this->app->getBasePath() . $appName . DIRECTORY_SEPARATOR;

        $this->app->setAppPath($appPath);
        // 设置应用命名空间
        $this->app->setNamespace($this->app->config->get('app.app_namespace') ?: 'app\\' . $appName);

        if (is_dir($appPath)) {
            $this->app->setRuntimePath($this->app->getRuntimePath() . $appName . DIRECTORY_SEPARATOR);
            $this->app->http->setRoutePath($this->getRoutePath());

            //加载应用
            $this->loadApp($appName, $appPath);
        }
    }

    /**
     * 加载应用文件
     * @param string $appName 应用名
     * @return void
     */
    protected function loadApp(string $appName, string $appPath): void
    {
        if (is_file($appPath . 'common.php')) {
            include_once $appPath . 'common.php';
        }

        $files = [];

        $files = array_merge($files, glob($appPath . 'config' . DIRECTORY_SEPARATOR . '*' . $this->app->getConfigExt()));

        foreach ($files as $file) {
            $this->app->config->load($file, pathinfo($file, PATHINFO_FILENAME));
        }

        if (is_file($appPath . 'event.php')) {
            $this->app->loadEvent(include $appPath . 'event.php');
        }

        if (is_file($appPath . 'middleware.php')) {
            $this->app->middleware->import(include $appPath . 'middleware.php', 'app');
        }

        if (is_file($appPath . 'provider.php')) {
            $this->app->bind(include $appPath . 'provider.php');
        }

        // 加载应用默认语言包
        $this->app->loadLangPack($this->app->lang->defaultLangSet());
    }

}
