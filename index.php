<?php
use GDO\Core\Application;
use GDO\Core\Debug;
use GDO\Core\Logger;
use GDO\Language\Trans;
use GDO\UI\GDT_Page;
use GDO\User\GDO_User;
use GDO\Session\GDO_Session;
use GDO\DB\Database;
use GDO\Core\ModuleLoader;
use GDO\Core\Website;
use GDO\Core\GDT_Error;
use GDO\DB\Cache;
use GDO\Core\GDT_Response;
use GDO\Core\GDT_Hook;
use GDO\UI\GDT_HTML;
use GDO\File\FileUtil;
use GDO\Core\Module_Core;
use GDO\Date\Time;

require 'GDO6.php';

@include GDO_PATH . 'protected/config.php';

if (!defined('GDO_CONFIGURED'))
{
	define('GDO_WEB_ROOT', '/');
    require 'index_install.php';
    die(1);
}

Database::init();
$noSession = true;
if (@class_exists('\\GDO\\Session\\GDO_Session', true))
{
    $noSession = false;
    GDO_Session::init(GDO_SESS_NAME, GDO_SESS_DOMAIN, GDO_SESS_TIME, !GDO_SESS_JS, GDO_SESS_HTTPS, GDO_SESS_SAMESITE);
}

# Bootstrap
Trans::$ISO = GDO_LANGUAGE;
Logger::init(null, GDO_ERROR_LEVEL); # 1st init as guest
Debug::init();
Debug::enableErrorHandler();
Debug::enableExceptionHandler();
Debug::setDieOnError(GDO_ERROR_DIE);
Debug::setMailOnError(GDO_ERROR_MAIL);

GDT_Page::make();

$app = new Application();

ModuleLoader::instance()->loadModulesCache();

$response = GDT_Response::make();

if (!module_enabled('Core'))
{
    require 'index_install.php';
    die(1);
}

if (!$noSession)
{
    $session = GDO_Session::instance();
}

$user = GDO_User::current();
if ($user->isUser())
{
	if ($name = $user->getUserName())
    {
    	Logger::init($name, GDO_ERROR_LEVEL); # 2nd init with username
    }
}

# Turn json content into $_REQUEST
if (@$_SERVER["CONTENT_TYPE"] === 'application/json')
{
	$data = file_get_contents('php://input');
	$data = json_decode($data, true);
	if (is_array($data))
	{
		$_REQUEST = array_merge($_REQUEST, $data);
	}
}

if (GDO_LOG_REQUEST)
{
    Logger::logRequest();
}

$app->initThemes();

# All fine!
define('GDO_CORE_STABLE', 1);

# File other than index.php requested
if ($url = @$_GET['_url'])
{
    # For directories, show the index, if configured
    if (is_dir($url))
    {
        $_REQUEST['mo'] = 'Core';
        $_REQUEST['me'] = 'DirectoryIndex';
        # .. fallthrough
    }
    
    # For virtual files, parse SEO urls :)
    elseif (!is_file($url))
    {
        if ($url === 'robots.txt')
        {
            $_REQUEST['mo'] = 'Core';
            $_REQUEST['me'] = 'Robots';
        }
        elseif ($url === 'security.txt')
        {
            $_REQUEST['mo'] = 'Core';
            $_REQUEST['me'] = 'Security';
        }
        else
        {
            $parts = explode('/', $url);
            $_REQUEST['mo'] = array_shift($parts);
            $_REQUEST['me'] = array_shift($parts);
            
            if (!$app->getMethod())
            {
                $_REQUEST['mo'] = 'Core';
                $_REQUEST['me'] = 'Page404';
            }
            
            else
            {
                while (count($parts))
                {
                    $key = array_shift($parts);
                    $var = array_shift($parts);
                    $_REQUEST[$key] = urldecode($var);
                }
                # .. fallthrough
            }
        }
    }
    
    # For real files, just serve it, unless it is css or javascript and module assets are disabled.
    else
    {
        $type = FileUtil::mimetype($url);
        $serve = false;
        if (str_ends_with($url, '.php'))
        {
            $_REQUEST['mo'] = 'Core';
            $_REQUEST['me'] = 'Page403';
        }
        elseif (str_starts_with($url, '.well-known/'))
        {
        	$serve = true;
        }
        elseif ( (($type === 'text/javascript') ||
                 ($type === 'text/css'))
               &&
                 (!Module_Core::instance()->checkAssetAllowance($url))
               )
        {
            $_REQUEST['mo'] = 'Core';
            $_REQUEST['me'] = 'Page403';
        }
        
        elseif (str_starts_with($url, '.'))
        {
        	
            $_REQUEST['mo'] = 'Core';
            $_REQUEST['me'] = 'Page403';
        }
        
        else
        {
        	$serve = true;
        }
        
        if ($serve)
        {
        	# serve static file with etag.
        	$last_modified_time = filemtime($url);
        	# @TODO: Cache etag-md5 via modified time
        	$etag = md5_file($url);
        	hdr("Last-Modified: ".gmdate("D, d M Y H:i:s", $last_modified_time)." GMT");
        	hdr("Etag: $etag");
        	hdr("Expires: ".gmdate("D, d M Y H:i:s", $last_modified_time + Time::ONE_MONTH)." GMT");
        	
        	# cache hit
        	if (@strtotime(@$_SERVER['HTTP_IF_MODIFIED_SINCE']) == $last_modified_time ||
        	    trim(@$_SERVER['HTTP_IF_NONE_MATCH']) == $etag)
        	{
        		hdrc('HTTP/1.1 304 Not Modified');
        		timingHeader();
        		die(0); 
        	}
        	
        	# 200 - serve
            hdr('Content-Type: '.$type);
            hdr('Content-Size: '.filesize($url));
            timingHeader();
            readfile($url);
            die(0); # no fallthrough!
        }
    }
}

try
{
	$rqmethod = @$_SERVER['REQUEST_METHOD'];
	$isOptions = $rqmethod === 'OPTIONS';
	if (!in_array($rqmethod, ['GET', 'POST', 'HEAD', 'OPTIONS'], true))
	{
		$_REQUEST['mo'] = 'Core';
		$_REQUEST['me'] = 'Page403';
	}

	# Exec
	if ($method = $app->getMethod())
	{
		if (!isset($_REQUEST['mo']))
		{
			$_REQUEST['mo'] = $method->getModuleName();
			$_REQUEST['me'] = $method->gdoShortName();
		}
	}
	
    ob_start();
    
    if (!$method)
    {
        $_REQUEST['mo'] = 'Core';
        $_REQUEST['me'] = 'Page404';
        $method = $app->getMethod();
    }
    
    ModuleLoader::instance()->initModulesB();

    if ($err = GDT_Response::globalError())
    {
    	# do nothing
    }
    
    elseif ($isOptions)
    {
    	die(0); # do nothing
    }
    	
    elseif (GDO_DB_ENABLED && GDO_SESS_LOCK && $method && $method->isLockingSession() && $session)
    {
        $lock = 'sess_'.$session->getID();
        Database::instance()->lock($lock);
        if (!$noSession)
        {
            GDO_Session::instance()->setLock($lock);
        }
    }
    
    $cacheContent = '';

    if (!$err)
    {
	    GDT_Hook::callHook('BeforeRequest', $method);
	    
	    if ($method && $method->fileCached())
	    {
	        $cacheContent = $cacheLoad = $method->fileCacheContent();
	    }
    }
   
    if ($err)
    {
    	# do nothing
    }
    elseif ($cacheContent)
    {
        $response = null;
        $content = $cacheContent;
        $method->setupSEO();
    }
    else
    {
        $response = GDT_Response::makeWith($method->exec());
    }

    if (!$err)
    {
	    GDT_Hook::callHook('AfterRequest', $method);
    }
}
catch (\Throwable $e)
{
	Logger::logException($e);
	Debug::debugException($e, false); # send exception mail
	$response = GDT_Error::responseException($e);
	$err = true;
}
finally
{
    $strayContent = ob_get_contents();
    ob_end_clean();
}

# Render Page
switch ($app->getFormat())
{
    case 'cli':
        if ($response)
        {
            hdr('Content-Type: application/text');
            $content = $strayContent;
            $cacheContent = $response->renderCLI();
            $content .= $cacheContent;
        }
        if ($session)
        {
            $session->commit();
        }
        break;
    case 'json':
        hdr('Content-Type: application/json');
        if ($response)
        {
            if ($strayContent)
            {
                $response->addField(GDT_HTML::make('content')->html($strayContent));
            }
            $response->addField(Website::$TOP_RESPONSE);
            $content = $response->renderJSON();
            if ($session)
            {
                $session->commit();
            }
            $content = $cacheContent = Website::renderJSON($content);
        }
        elseif ($session)
        {
            $session->commit();
        }
        break;
        
    case 'html':
        
        if ($ajax = $app->isAjax())
        {
            hdr('Content-Type: text/plain');
        }
        
        if ($response)
        {
            $content = $strayContent;
            $cacheContent = $response->renderHTML();
            $content .= $cacheContent;
            if (!$ajax)
            {
            	ModuleLoader::instance()->onIncludeScripts();
            	$content = GDT_Page::$INSTANCE->html($content)->render();
            }
            if ($session)
            {
                $session->commit();
            }
        }
        else
        {
            if (!$ajax)
            {
            	ModuleLoader::instance()->onIncludeScripts();
            	$content = GDT_Page::$INSTANCE->html($cacheContent)->render();
            }
            if ($session)
            {
                $session->commit();
            }
        }
        break;
        
    case 'xml':
        hdr('Content-Type: application/xml');
        if ($response)
        {
            $content = $cacheContent = $response->renderXML();
        }
        if ($session)
        {
            $session->commit();
        }
        break;
}

if ($method && $method->fileCached() && (!$cacheLoad))
{
    $key = $method->fileCacheKey();
    Cache::fileSet($key, $cacheContent);
}

# Fire recache IPC events. Probably disabled
Cache::recacheHooks();

timingHeader();

function timingHeader()
{
    hdr(sprintf('X-GDO-TIME: %.04f',
        (microtime(true) - GDO_PERF_START)));
}

echo $content;
