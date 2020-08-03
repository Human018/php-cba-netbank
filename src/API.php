<?php

namespace Kravock\Netbank;

use Goutte\Client;
use GuzzleHttp\Client as GuzzleClient;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\InputFormField;

class API
{
    private $username = '';
    private $password = '';
    private $client;
    private $guzzleClient;
    private $timezone = 'Australia/Sydney';

    const BASE_URL = 'https://www.my.commbank.com.au/';
    const LOGIN_URL = 'netbank/Logon/Logon.aspx';

    /**
     * Create a new API Instance
     */
    public function __construct()
    {
        $this->client = new Client();
        $this->guzzleClient = new GuzzleClient(array(
            'allow_redirects' => true,
            'timeout' => 60,
            'cookies' => true,
            'headers' => [
                'User-Agent' => "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_10_1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2227.1 Safari/537.36"
            ]
        ));

        $this->client->setClient($this->guzzleClient);
    }

    public function login($username, $password)
    {
        $crawler = $this->client->request('GET', sprintf("%s%s", self::BASE_URL, self::LOGIN_URL));

        $form = $crawler->selectButton('Log on')->form();

        $fields = $crawler->filter('input');

        // We need to set fields to enabled otherwise we can't login
        foreach ($fields as $field) {
            $field->removeAttribute('disabled');
        }

        $form['txtMyClientNumber$field'] = $username;
        $form['txtMyPassword$field'] = $password;
        $form['JS'] = 'E';

        $crawler = $this->client->submit($form);

        $accountList = [];

        $crawler->filter('.main_group_account_row')->each(function ($account) use (&$accountList) {
            $name = $account;
            $name = $name->filter('.NicknameField a')->first();

            $bsb = $account;
            $bsb = $bsb->filter('.BSBField .field')->first();

            $accountNumber = $account;
            $accountNumber = $accountNumber->filter('.AccountNumberField .field')->first();

            $balance = $account;
            $balance = $balance->filter('td.AccountBalanceField span.Currency')->first();

            $available = $account;
            $available = $available->filter('td.AvailableFundsField span.Currency')->first();

            $bal = $balance->count() ? $balance->text() : 0;
            $avl = $available->count() ? $available->text() : 0;

            $accountList[] = [
                'nickname' => $name->text(),
                'url' => $name->attr('href'),
                'bsb' => $bsb->count() ? $bsb->text() : '',
                'accountNum' => $accountNumber->count() ? $accountNumber->text() : '',
                'balance' => $this->processCurrency($bal),
                'available' => $this->processCurrency($avl)
            ];
        });

        if (!$accountList) {
            throw new \Exception('Unable to retrieve account list.');
        }

        return $accountList;
    }

    public function getTransactions($account, $from, $to)
    {
        $link = sprintf("%s%s", self::BASE_URL, $account['url']);
        $crawler = $this->client->request('GET', $link);

        $form = $crawler->filter('#aspnetForm');

        // Check that we we a form on the transaction page
        if (!$form->count()) {
            return [];
        }

        $form = $form->form();

        $field = $this->createField('input', '__EVENTTARGET', 'ctl00$BodyPlaceHolder$lbSearch');
        $form->set($field);

        $field = $this->createField('input', 'ctl00$ctl00', 'ctl00$BodyPlaceHolder$updatePanelSearch|ctl00$BodyPlaceHolder$lbSearch');
        $form->set($field);

        $field = $this->createField('input', 'ctl00$BodyPlaceHolder$searchTypeField', '1');
        $form->set($field);

        $field = $this->createField('input', 'ctl00$BodyPlaceHolder$radioSwitchDateRange$field$', 'ChooseDates');
        $form->set($field);

        $field = $this->createField('input', 'ctl00$BodyPlaceHolder$dateRangeField', 'ChooseDates');
        $form->set($field);

        $field = $this->createField('input', 'ctl00$BodyPlaceHolder$fromCalTxtBox$field', $from);
        $form->set($field);

        $field = $this->createField('input', 'ctl00$BodyPlaceHolder$toCalTxtBox$field', $to);
        $form->set($field);

        $field = $this->createField('input', 'ctl00$BodyPlaceHolder$radioSwitchSearchType$field$', 'AllTransactions');
        $form->set($field);

        $crawler = $this->client->submit($form);
        $html = $crawler->html();

        preg_match_all('/({"Transactions":(?:.+)})\);/', $html, $matches);

        $transactions = [];
        foreach ($matches[1] as $_temp) {
            if (strstr($_temp, 'Transactions')) {
                $transactions = json_decode($_temp);
                break;
            }
        }

        $transactionList = [];
        if (!empty($transactions->Transactions)) {
            foreach ($transactions->Transactions as $transaction) {
                if (intval($transaction->Date->Sort[1]) > 200000)
                    $date = \DateTime::createFromFormat('YmdHisu', substr($transaction->Date->Sort[1], 0, 20), new \DateTimeZone('UTC'));
                else
                    $date = new \DateTime($transaction->Date->Text);
                $date->setTimeZone(new \DateTimeZone($this->timezone));
                $transactionList[] = [
                    'timestamp' => $transaction->Date->Sort[1],
                    'date' => $date->format('Y-m-d H:i:s.u'),
                    'description' => $transaction->Description->Text,
                    'amount' => $this->processCurrency($transaction->Amount->Text),
                    'balance' => $this->processCurrency($transaction->Balance->Text),
                    'trancode' => $transaction->TranCode->Text,
                    'receiptnumber' => $transaction->ReceiptNumber->Text,
                    'url' => $transaction->Description->Url,
                ];
            }
        }

        return $transactionList;
    }

    public function getTransactionDetails($url)
    {
        $link = sprintf("%s%s", self::BASE_URL, substr($url, 1));
        
        // Server updates '_CBAPVCOOKIE' cookie, but this throws a security error on all subsequent requests.
        $cookieJar = $this->client->getCookieJar();
        $orig = $cookieJar->get('_CBAPVCOOKIE');
        $result = $this->client->request('POST', $link, [], [], [], null, false);
        $cookieJar->set($orig);
        
        $crawler = new \Symfony\Component\DomCrawler\Crawler($result->text());
        
        $crawler = $crawler->filterXPath("//table/*/tr");
        
        $nodeValues = $crawler->each(
                function (Crawler $node, $i) {
                        $first = trim($node->children()->first()->text());
                        $last = trim($node->children()->last()->text());
                        if (strpos($first, "\n") !== false)
                            return;
                        else
                            return array($first => $last);
                }
        );
        $values = array();
        foreach ($nodeValues as $node) {
            if (is_array($node))
                $values = array_merge($values, $node);
        }
        return $values;
    }

    public function setTimezone($timezone)
    {
        $this->timezone = $timezone;
    }

    private function processCurrency($amount)
    {
        $value = preg_replace('$[^0-9.]$', '', $amount);

        if (strstr($amount, 'DR')) {
            $value = -$value;
        }

        return $value;
    }

    private function createField($type, $name, $value)
    {
        $domdocument = new \DOMDocument;
        $ff = $domdocument->createElement($type);
        $ff->setAttribute('name', $name);
        $ff->setAttribute('value', $value);
        $formfield = new InputFormField($ff);

        return $formfield;
    }
}
