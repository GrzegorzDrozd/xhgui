<?php

use Slim\Slim;

class Xhgui_Controller_Watch extends Xhgui_Controller
{
    /**
     * @var \Xhgui_WatchedFunctionsStorageInterface
     */
    protected $watches;

    /**
     * Xhgui_Controller_Watch constructor.
     * @param Slim $app
     * @param Xhgui_WatchedFunctionsStorageInterface $watches
     */
    public function __construct(Slim $app, \Xhgui_WatchedFunctionsStorageInterface $watches)
    {
        parent::__construct($app);
        $this->setWatches($watches);
    }

    public function get()
    {
        $watched = $this->getWatches()->getWatchedFunctions();

        $this->_template = 'watch/list.twig';
        $this->set(array('watched' => $watched));
    }

    public function post()
    {
        $app = $this->app;
        $watches = $this->watches;

        $saved = false;
        $request = $app->request();
        foreach ((array)$request->post('watch') as $data) {
            if (empty($data['id'])) {
                $watches->addWatchedFunction($data['name']);
            } elseif (!empty($data['removed']) && $data['removed'] === '1') {
                $watches->removeWatchedFunction($data['id']);
            } else {
                $watches->updateWatchedFunction($data['id'], $data['name']);
            }
            $saved = true;
        }
        if ($saved) {
            $app->flash('success', 'Watch functions updated.');
        }
        $app->redirect($app->urlFor('watch.list'));
    }

    /**
     * @return Xhgui_WatchedFunctionsStorageInterface
     */
    public function getWatches()
    {
        return $this->watches;
    }

    /**
     * @param Xhgui_WatchedFunctionsStorageInterface $watches
     */
    public function setWatches($watches)
    {
        $this->watches = $watches;
    }
}
