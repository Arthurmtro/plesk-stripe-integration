<?php
class IndexController extends pm_Controller_Action
{
    private $pleskClients = [];

    public function init()
    {
        parent::init();
        // Init tabs for all actions
        $this->view->tabs = [
            [
                'title' => $this->lmsg('tabList'),
                'action' => 'list',
            ],
            [
                'title' => $this->lmsg('tabSettings'),
                'action' => 'settings',
            ],
        ];

        $pleskClients = pm_Session::getClient();

        if (!$pleskClients->isClient()) {
            $request = '<packet version="1.6.7.0">
                            <customer>
                               <get>
                                  <filter>
                                  </filter>
                                  <dataset>
                                      <gen_info/>
                                  </dataset>
                               </get>
                            </customer>
                        </packet>';

            $res = pm_ApiRpc::getService()->call($request)->customer->get;

            $this->pleskClients = $res;

            $this->view->clients = $res;

            $this->getStripeInfos();
        }else {
            $this->view->clients = $this->lmsg("noPermissions");
        }

        $this->view->clients = $this->lmsg("noCustomers");
    }

    public function indexAction()
    {
        if(pm_Settings::get('stripeApiKey')) {
            $this->_forward('list');
        }else {
            $this->_forward('settings');
        }
    }

    public function settingsAction()
    {
        $form = new pm_Form_Simple;

        $form->addElement('text', 'stripeApiKey', [
            'label' => $this->lmsg('formStripeAPIKeyField'),
            'value' => pm_Settings::get('stripeApiKey'),
            'validator' => 'alnum',
            'required' => true,
        ]);

        $form->addElement('text', 'stripeUrl', [
            'label' => $this->lmsg('formStripeUrlField'),
            'value' => pm_Settings::get('stripeUrl'),
            'validator' => 'alnum',
            'required' => false,
        ]);

        $form->addControlButtons();

        if ($this->getRequest()->isPost() && $form->isValid($this->getRequest()->getPost())) {
            $stripeApiKey = $form->getValue('stripeApiKey');
            $stripeApiUrl = $form->getValue('stripeUrl');

            pm_Settings::set('stripeApiKey', $stripeApiKey);
            pm_Settings::set('stripeUrl', $stripeApiUrl);

            $this->_status->addInfo('Data was successfully saved.');
            $this->_helper->json(['redirect' => pm_Context::getBaseUrl() ]);
        }

        $this->view->form = $form;
    }

    public function listAction()
    {
        $list = $this->_getList();
        // List object for pm_View_Helper_RenderList
        $this->view->list = $list;
    }

    public function listDataAction()
    {
        $list = $this->_getList();
        // Json data from pm_View_List_Simple
        $this->_helper->json($list->fetchData());
    }

    private function clg($output, $with_script_tags = true) {
        $js_code = 'console.log(' . json_encode($output, JSON_HEX_TAG) .
            ');';
        if ($with_script_tags) {
            $js_code = '<script>' . $js_code . '</script>';
        }
        echo $js_code;
    }

    private function url_get_contents($url, $useragent='cURL', $headers=false, $follow_redirects=true, $debug=false) {
        // initialise the CURL library
        $ch = curl_init();

        $result = null;

        // specify the URL to be retrieved
        curl_setopt($ch, CURLOPT_URL,$url);

        // we want to get the contents of the URL and store it in a variable
        curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);

        // specify the useragent: this is a required courtesy to site owners
        curl_setopt($ch, CURLOPT_USERAGENT, $useragent);

        // ignore SSL errors
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        // return headers as requested
        if ($headers){
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        // only return headers
        if ($headers=='headers only') {
            curl_setopt($ch, CURLOPT_NOBODY ,1);
        }

        // follow redirects - note this is disabled by default in most PHP installs from 4.4.4 up
        if ($follow_redirects) {
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        }

        // if debugging, return an array with CURL's debug info and the URL contents
        if ($debug) {
            $result['contents']=curl_exec($ch);
            $result['info']=curl_getinfo($ch);
        } else {
            // otherwise just return the contents as a variable
            $result = curl_exec($ch);
        }

        // free resources
        curl_close($ch);

        $response = json_decode($result);

        //$this->clg($response);

        // send back the data
        return $response;
    }

    private function getStripeInfos() {

        $bearer = pm_Settings::get('stripeApiKey');
        //$bearer = "Bearer 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";

        $headers = array('Authorization:' . $bearer);

        $stripeSub = $this->url_get_contents("https://api.stripe.com/v1/subscriptions", "cURL", $headers);

        $stripeInfos = $this->url_get_contents("https://api.stripe.com/v1/customers", "cURL", $headers);

        foreach ($stripeInfos->data as $info) {
            foreach ($stripeSub->data as $sub) {
                if($info->id == $sub->customer) {
                    $info->status = $sub->status;
                    $info->current_period_end = $sub->current_period_end;
                    break;
                }else {
                    $info->status = "inactive";
                }
            }
        }

        //$this->clg($stripeSub);
        //$this->clg($stripeInfos);

        foreach ($this->pleskClients->result as $client) {
            $login = pm_Session::getClient()->getLogin();
            $domain =  "https://{$_SERVER['HTTP_HOST']}/{$login}/customer/domains/id/{$client->id}";

            $client->data->gen_info->pleskUrl = $domain;

            foreach ($stripeInfos->data as $info) {
                if($client->data->gen_info->email == $info->email) {

                    if($info->current_period_end != null) {
                        $date = new DateTime();
                        $date->setTimestamp((int) $info->current_period_end);
                        $formatDate = $date->format('d-m-Y');
                    }else {
                        $formatDate = null;
                    }

                    $client->data->gen_info->status = $info->status;
                    $client->data->gen_info->current_period_end = $formatDate;
                    break;
                }else {
                    $client->data->gen_info->status = "inactive";
                }
            }
        }
    }

    private function _getList()
    {
        $data = [];
        foreach ($this->pleskClients->result as $client) {

            $data[] = [
                'column-1' => '<a href="'. $client->data->gen_info->pleskUrl . '">' . $client->data->gen_info->email . '</a>',
                'column-2' => '<p>'. $client->data->gen_info->status .'</p>',
                'column-3' => '<p>'. $client->data->gen_info->current_period_end . '</p>',
            ];
        }
        $list = new pm_View_List_Simple($this->view, $this->_request);
        $list->setData($data);

        $list->setColumns([
            'column-1' => [
                'title' => $this->lmsg('emailCol'),
                'noEscape' => true,
                'searchable' => true,
                'sortable' => true,
            ],
            'column-2' => [
                'title' => $this->lmsg('statusCol'),
                'noEscape' => true,
                'sortable' => true,
            ],
            'column-3' => [
                'title' => $this->lmsg('endDateCol'),
                'noEscape' => true,
                'sortable' => true,
            ],
        ]);
        // Take into account listDataAction corresponds to the URL /list-data/
        $list->setDataUrl(['action' => 'list-data']);
        return $list;
    }
}
?>