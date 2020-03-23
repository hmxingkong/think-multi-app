# think-multi-app
ThinkPHP 6.* 多应用功能支持，基于ThinkPHP官方同名插件(topthink/think-multi-app: 1.0.12)修改，
扩展通过路由文件解析appName的功能，解决官方插件只能通过入口文件或者url指定appName的问题

-------------------------------------------------

## 安装

使用composer安装

~~~
composer require hmxingkong/think-multi-app
~~~
提示： 包名可指定版本号，e.g. hmxingkong/think-multi-app or hmxingkong/think-multi-app:1.0.0 or hmxingkong/think-multi-app=1.0.0 or "hmxingkong/think-multi-app 1.0.0"

使用工具包
~~~
安装即可默认启用，注意如果已安装官方 topthink/think-multi-app，请卸载，本插件已包含官方插件的功能
~~~

更新工具包
~~~
composer update hmxingkong/think-multi-app
~~~

v1.1.0 更新内容
 + 优化多应用识别策略，兼容 app/miss 应用，appName 确认优先级： 手工指定（URL/入口文件） > 配置指定（app.domain_bind/app.app_map） > 动态识别 > 默认Miss应用（app/miss） > 默认应用（app.default_app）

v1.0.0 更新内容
 + 初始版本，基于 topthink/think-multi-app: 1.0.12 进行优化，支持通过路由文件解析 appName，支持加载 RootPath/AppName/route、RootPath/route/AppName、RootPath/route 三个位置的路由配置加载，优先级 RootPath/AppName/route > RootPath/route/AppName > RootPath/route
  