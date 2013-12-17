<?php
namespace GearmanHandler;

use GearmanWorker;
use React\EventLoop\Factory as Loop;
use Exception;
use Closure;

class Daemon
{
    /** @var array $callbacks */
    private $callbacks = [];

    /** @var \React\EventLoop\StreamSelectLoop|\React\EventLoop\LibEventLoop $loop */
    private $loop;

    /** @var bool|resource $lock */
    private $lock = false;

    /** @var bool $kill */
    private $kill = false;

    /** @var \GearmanWorker $worker */
    private $worker;

    /** @var array $registered_workers */
    private $registered_workers = [];

    public function __construct()
    {
        $this->loop = Loop::create();
    }

    public function __destruct()
    {
        if (is_resource($this->lock)) {
            Process::release($this->lock);
        }
    }

    public function run($fork = true)
    {
        if ($fork) {
            $pid = pcntl_fork();
            Process::setPid($pid);
        }

        if (!$fork || (isset($pid) && $pid !== -1 && !$pid)) {
            $this->lock = Process::lock();
            $this->signalHandlers();
            $this->createWorker();
            $this->registerWorkers();
            $this->createLoop();
        }
    }

    public function signalHandlers()
    {
        $root = $this;
        pcntl_signal(SIGUSR1, function () use ($root) {
            $root->setKill(true);
        });
    }

    private function createWorker()
    {
        $this->worker = new GearmanWorker();
        $this->worker->addServer(Config::getGearmanHost(), Config::getGearmanPort());
    }

    private function createLoop()
    {
        $root = $this;
        $worker = $this->worker;
        $loop = $this->loop;

        $loop->addPeriodicTimer(.05, function () use ($loop, $worker, $root) {
            pcntl_signal_dispatch();
            $callbacks = $root->getCallbacks();
            if (count($callbacks)) {
                foreach($callbacks as $callback) {
                    $callback($root);
                }
            }
            if ($root->getKill()) {
                $loop->stop();
            }
        });

        $loop->run();
    }

    /**
     * @return array
     */
    public function getRegisteredWorkers()
    {
        return $this->registered_workers;
    }

    /**
     * @param null $dir
     * @throws \Exception
     */
    private function registerWorkers($dir = null)
    {
        if (null === $dir) {
            $dir = Config::getWorkerDir();
        }

        if (is_dir($dir)) {
            foreach (scandir($dir) as $file) {
                if ($file !== '.' && $file !== '..' && is_dir($file)) {
                    $file = $dir . DIRECTORY_SEPARATOR . $file;
                    $this->registerWorkers($file);
                } elseif (strtolower(substr($file, -4)) === '.php') {
                    $file = $dir . DIRECTORY_SEPARATOR . $file;
                    $className = $this->getClassNameFromFile($file);
                    $className = (!empty($className[0]) ? $className[0] . '\\' : '') . $className[1];

                    require_once $file;

                    $class = new $className;

                    if (!$class instanceof Job) {
                        throw new Exception('Class ' . $className . ' does not implements GearmanHandler\\Job interface');
                    } else {
                        $this->registered_workers[] = $className;
                        $this->worker->addFunction($class->getName(), [$className, 'execute']);
                    }
                }
            }
        }
    }

    /**
     * @param string $file
     * @return array
     */
    private function getClassNameFromFile($file)
    {
        $fp = fopen($file, 'r');
        $class = $namespace = $buffer = '';
        $i = 0;
        while (!$class) {
            if (feof($fp)) break;

            $buffer .= fread($fp, 512);
            $tokens = token_get_all($buffer);

            if (strpos($buffer, '{') === false) continue;

            for (;$i<count($tokens);$i++) {
                if ($tokens[$i][0] === T_NAMESPACE) {
                    for ($j=$i+1;$j<count($tokens); $j++) {
                        if ($tokens[$j][0] === T_STRING) {
                            $namespace .= '\\'.$tokens[$j][1];
                        } else if ($tokens[$j] === '{' || $tokens[$j] === ';') {
                            break;
                        }
                    }
                }

                if ($tokens[$i][0] === T_CLASS) {
                    for ($j=$i+1;$j<count($tokens);$j++) {
                        if ($tokens[$j] === '{') {
                            $class = $tokens[$i+2][1];
                        }
                    }
                }
            }
        }
        return [$namespace, $class];
    }

    /**
     * @param callable $callback
     */
    public function addCallback(Closure $callback)
    {
        $this->callbacks[] = $callback;
    }

    /**
     * @return array
     */
    public function getCallbacks()
    {
        return $this->callbacks;
    }

    /**
     * @param \React\EventLoop\StreamSelectLoop|\React\EventLoop\LibEventLoop $loop
     * @return $this
     */
    public function setLoop($loop)
    {
        $this->loop = $loop;
        return $this;
    }

    /**
     * @return \React\EventLoop\LibEventLoop|\React\EventLoop\StreamSelectLoop
     */
    public function getLoop()
    {
        return $this->loop;
    }

    /**
     * @return bool
     */
    public function getKill()
    {
        return $this->kill;
    }

    /**
     * @param $kill
     * @return $this
     */
    public function setKill($kill)
    {
        $this->kill = $kill;
        return $this;
    }
}