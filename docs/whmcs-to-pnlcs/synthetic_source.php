<?php

/**
 * Builds a tiny synthetic WHMCS database for verification. It never touches
 * the application's own database. Run with the project's PHP binary:
 *
 *   php docs/whmcs-to-pnlcs/synthetic_source.php
 *
 * Optional environment: WTP_TEST_SOURCE_DB (default pnlcs_wtptest_source).
 * The application database from .env (or WTP_APP_DB) can never be dropped
 * unless WTP_ALLOW_APP_DB=1 is explicitly set.
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$cfg = config('database.connections.mysql');
$sourceDb = getenv('WTP_TEST_SOURCE_DB') ?: 'pnlcs_wtptest_source';

if (! preg_match('/^[A-Za-z0-9_]+$/', $sourceDb)) {
    fwrite(STDERR, "Invalid WTP_TEST_SOURCE_DB name\n");
    exit(1);
}

// This script drops and recreates the source database. Identify the real
// application database from .env, not from config(), because DB_DATABASE may
// be overridden when running verification against a disposable target.
$applicationDb = getenv('WTP_APP_DB');
if (! is_string($applicationDb) || $applicationDb === '') {
    $envFile = base_path('.env');
    if (! is_file($envFile)) {
        fwrite(STDERR, "Cannot locate .env to identify the application database; set WTP_APP_DB.\n");
        exit(1);
    }
    foreach (file($envFile) as $line) {
        if (preg_match('/^\s*DB_DATABASE\s*=\s*(.*?)\s*$/', $line, $match)) {
            $applicationDb = trim(preg_replace('/\s+#.*$/', '', $match[1]) ?? '');
            if (preg_match('/^(["\'])(.*)\1$/', $applicationDb, $quoted)) {
                $applicationDb = $quoted[2];
            }
            break;
        }
    }
}
$applicationDb = trim((string) $applicationDb);

if ($applicationDb === '') {
    fwrite(STDERR, "Cannot identify the application database; set WTP_APP_DB.\n");
    exit(1);
}
if ($sourceDb === $applicationDb && getenv('WTP_ALLOW_APP_DB') !== '1') {
    fwrite(STDERR, "Refusing to drop the application database ($sourceDb).\n");
    exit(1);
}

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $cfg['host'], $cfg['port']),
    (string) $cfg['username'],
    (string) $cfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$pdo->exec('DROP DATABASE IF EXISTS `'.$sourceDb.'`');
$pdo->exec('CREATE DATABASE `'.$sourceDb.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE `'.$sourceDb.'`');

$schema = <<<'SQL'
CREATE TABLE tblconfiguration (id INT AUTO_INCREMENT PRIMARY KEY, setting VARCHAR(100), value TEXT);
CREATE TABLE tblcurrencies (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(3), prefix VARCHAR(10), suffix VARCHAR(10), format INT, rate DECIMAL(10,5));
CREATE TABLE tblclientgroups (id INT AUTO_INCREMENT PRIMARY KEY, groupname VARCHAR(100));
CREATE TABLE tblclients (
  id INT AUTO_INCREMENT PRIMARY KEY, uuid VARCHAR(36), firstname VARCHAR(100), lastname VARCHAR(100),
  companyname VARCHAR(100), email VARCHAR(150), address1 VARCHAR(150), address2 VARCHAR(150) DEFAULT NULL,
  city VARCHAR(80), state VARCHAR(80) DEFAULT NULL, postcode VARCHAR(20) DEFAULT NULL, country VARCHAR(2),
  phonenumber VARCHAR(255), tax_id VARCHAR(50) DEFAULT NULL, status VARCHAR(20), language VARCHAR(20) DEFAULT NULL,
  notes TEXT NULL, credit DECIMAL(15,2), taxexempt TINYINT DEFAULT 0, ip VARCHAR(45) DEFAULT NULL,
  datecreated DATETIME, groupid INT, currency INT
);
CREATE TABLE tblcontacts (
  id INT AUTO_INCREMENT PRIMARY KEY, userid INT, firstname VARCHAR(100), lastname VARCHAR(100) DEFAULT NULL,
  email VARCHAR(150), companyname VARCHAR(100) DEFAULT NULL, address1 VARCHAR(150) DEFAULT NULL,
  address2 VARCHAR(150) DEFAULT NULL, city VARCHAR(80) DEFAULT NULL, state VARCHAR(80) DEFAULT NULL,
  postcode VARCHAR(20) DEFAULT NULL, country VARCHAR(2) DEFAULT NULL, phonenumber VARCHAR(30) DEFAULT NULL,
  subaccount TINYINT DEFAULT 0, generalemails TINYINT DEFAULT 0, productemails TINYINT DEFAULT 0,
  domainemails TINYINT DEFAULT 0, invoiceemails TINYINT DEFAULT 0, supportemails TINYINT DEFAULT 0
);
CREATE TABLE tblcustomfields (
  id INT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(30), relid INT DEFAULT 0, fieldname VARCHAR(150),
  fieldtype VARCHAR(30), description TEXT NULL, fieldoptions TEXT NULL, regexpr TEXT NULL,
  adminonly TEXT NULL, required TEXT NULL, showorder TEXT NULL, showinvoice TEXT NULL, sortorder INT DEFAULT 0
);
CREATE TABLE tblproductgroups (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100), headline TEXT NULL, tagline TEXT NULL, hidden TINYINT, `order` INT);
CREATE TABLE tblproducts (
  id INT AUTO_INCREMENT PRIMARY KEY, gid INT, name VARCHAR(150), description TEXT, type VARCHAR(50),
  hidden TINYINT, paytype VARCHAR(30), autosetup VARCHAR(30), servertype VARCHAR(50), tax TINYINT, `order` INT,
  welcomeemail INT, servergroup INT
);
CREATE TABLE tbladdons (
  id INT AUTO_INCREMENT PRIMARY KEY, packages TEXT NULL, name VARCHAR(150), description TEXT NULL,
  billingcycle VARCHAR(30) DEFAULT NULL, tax TINYINT DEFAULT 0, showorder TINYINT DEFAULT 0,
  hidden TINYINT DEFAULT 0, retired TINYINT DEFAULT 0, weight INT DEFAULT 0
);
CREATE TABLE tblservers (
  id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150), hostname VARCHAR(150), ipaddress VARCHAR(45) DEFAULT NULL,
  maxaccounts INT DEFAULT 0, type VARCHAR(50) DEFAULT NULL, username VARCHAR(100) DEFAULT NULL,
  port INT DEFAULT NULL, active TINYINT DEFAULT 0, nameserver1 TEXT NULL, nameserver2 TEXT NULL,
  nameserver3 TEXT NULL, nameserver4 TEXT NULL, nameserver5 TEXT NULL
);
CREATE TABLE tblpricing (
  id INT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(20), relid INT, currency INT, msetupfee DECIMAL(15,2),
  qsetupfee DECIMAL(15,2), ssetupfee DECIMAL(15,2), asetupfee DECIMAL(15,2), bsetupfee DECIMAL(15,2),
  tsetupfee DECIMAL(15,2), monthly DECIMAL(15,2), quarterly DECIMAL(15,2), semiannually DECIMAL(15,2),
  annually DECIMAL(15,2), biennially DECIMAL(15,2), triennially DECIMAL(15,2)
);
CREATE TABLE tblhosting (
  id INT AUTO_INCREMENT PRIMARY KEY, userid INT, packageid INT, server INT, orderid INT, domain VARCHAR(150),
  username VARCHAR(100), qty INT, firstpaymentamount DECIMAL(15,2), amount DECIMAL(15,2),
  billingcycle VARCHAR(30), nextduedate DATE, regdate DATE, domainstatus VARCHAR(30), diskusage INT,
  disklimit INT, bwusage INT, bwlimit INT, notes TEXT NULL, suspendreason TEXT NULL
);
CREATE TABLE tblorders (
  id INT AUTO_INCREMENT PRIMARY KEY, ordernum VARCHAR(50), userid INT, `date` DATE, promocode VARCHAR(50) DEFAULT NULL,
  amount DECIMAL(15,2), paymentmethod VARCHAR(50), invoiceid INT, status VARCHAR(30), ipaddress VARCHAR(45) DEFAULT NULL,
  fraudmodule VARCHAR(50) DEFAULT NULL, fraudoutput TEXT NULL, notes TEXT NULL
);
CREATE TABLE tblinvoices (
  id INT AUTO_INCREMENT PRIMARY KEY, userid INT, invoicenum VARCHAR(50), `date` DATE, duedate DATE, datepaid DATETIME,
  subtotal DECIMAL(15,2), credit DECIMAL(15,2) DEFAULT 0, tax DECIMAL(15,2), tax2 DECIMAL(15,2) DEFAULT 0,
  total DECIMAL(15,2), taxrate DECIMAL(8,3), taxrate2 DECIMAL(8,3) DEFAULT 0, status VARCHAR(30),
  paymentmethod VARCHAR(50), notes TEXT NULL
);
CREATE TABLE tblinvoiceitems (
  id INT AUTO_INCREMENT PRIMARY KEY, invoiceid INT, userid INT, type VARCHAR(30), relid INT, description TEXT,
  amount DECIMAL(15,2), taxed TINYINT, duedate DATE
);
CREATE TABLE tblaccounts (
  id INT AUTO_INCREMENT PRIMARY KEY, userid INT, invoiceid INT, gateway VARCHAR(50), `date` VARCHAR(30), description TEXT,
  amountin DECIMAL(15,2), amountout DECIMAL(15,2), fees DECIMAL(15,2), rate DECIMAL(10,5), transid VARCHAR(100), currency INT
);
CREATE TABLE tblticketdepartments (
  id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100), description TEXT NULL, email VARCHAR(150) DEFAULT NULL,
  clientsonly TEXT, hidden TEXT, `order` INT DEFAULT 0
);
CREATE TABLE tbltickets (
  id INT AUTO_INCREMENT PRIMARY KEY, tid VARCHAR(128), userid INT, did INT, name VARCHAR(100), email VARCHAR(150),
  cc TEXT NULL, title VARCHAR(200), message TEXT, status VARCHAR(30), urgency VARCHAR(30), admin VARCHAR(100),
  lastreply DATETIME NULL, `date` DATETIME
);
CREATE TABLE tblticketreplies (
  id INT AUTO_INCREMENT PRIMARY KEY, tid INT, userid INT, contactid INT, message TEXT, admin VARCHAR(100),
  rating INT DEFAULT 0, `date` DATETIME
);
CREATE TABLE tblticketnotes (
  id INT AUTO_INCREMENT PRIMARY KEY, ticketid INT, admin VARCHAR(100), message TEXT, attachments TEXT NULL, `date` DATETIME
);
CREATE TABLE tbldomains (
  id INT AUTO_INCREMENT PRIMARY KEY, userid INT, orderid INT, domain VARCHAR(150), type VARCHAR(30),
  registrar VARCHAR(100), registrationperiod INT, registrationdate DATE, expirydate DATE, nextduedate DATE,
  status VARCHAR(30), dnsmanagement TINYINT DEFAULT 0, emailforwarding TINYINT DEFAULT 0,
  idprotection TINYINT DEFAULT 0, paymentmethod VARCHAR(50), firstpaymentamount DECIMAL(15,2),
  recurringamount DECIMAL(15,2), additionalnotes TEXT NULL
);
CREATE TABLE tblcredit (
  id INT AUTO_INCREMENT PRIMARY KEY, clientid INT, admin_id INT DEFAULT 0, `date` DATE,
  description TEXT, amount DECIMAL(15,2), relid INT DEFAULT 0
);
CREATE TABLE tblnotes (
  id INT AUTO_INCREMENT PRIMARY KEY, userid INT, adminid INT DEFAULT 0, created DATETIME,
  modified DATETIME, note TEXT, sticky INT DEFAULT 0
);
CREATE TABLE tblcancelrequests (
  id INT AUTO_INCREMENT PRIMARY KEY, `date` DATETIME, relid INT, reason TEXT, type TEXT
);
CREATE TABLE tblcreditcards (
  id INT AUTO_INCREMENT PRIMARY KEY, pay_method_id INT DEFAULT 0, card_type VARCHAR(255),
  last_four VARCHAR(255), expiry_date DATETIME, card_data BLOB NULL
);
CREATE TABLE tblactivitylog (id INT AUTO_INCREMENT PRIMARY KEY, `date` DATETIME, description TEXT, `user` VARCHAR(100), ipaddr VARCHAR(45));
SQL;

foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
    $pdo->exec($statement);
}

$pdo->exec("INSERT INTO tblconfiguration (setting, value) VALUES ('Version','8.10.1'), ('TimeZone','Europe/Warsaw')");
$pdo->exec("INSERT INTO tblcurrencies (code,prefix,suffix,format,rate) VALUES ('USD','\$','',1,1.00000), ('EUR','€','',1,0.92000)");
$pdo->exec("INSERT INTO tblclientgroups (groupname) VALUES ('Retail'), ('Resellers')");

for ($i = 1; $i <= 5; $i++) {
    $lastName = $i === 5 ? '' : sprintf('Test%d', $i);
    $company = $i === 5 ? 'Company Five' : '';
    // Client 5 also carries a phone number longer than the original PNLCS
    // varchar(30) column, exercising the widening migration.
    $phone = $i === 5 ? '+91..822325381725422099000007319035353512' : sprintf('+4812345678%d', $i);
    $pdo->exec(sprintf(
        "INSERT INTO tblclients (firstname,lastname,companyname,email,address1,city,country,phonenumber,status,datecreated,groupid,currency,credit) VALUES ('Client%d','%s','%s','client%d@example.test','Main St %d','Warsaw','PL','%s','Active','2023-01-%02d 10:00:00',%d,%d,%s)",
        $i, $lastName, $company, $i, $i, $phone, $i, $i, $i % 2 + 1, $i === 1 ? 25.50 : 0
    ));
}

$pdo->exec("INSERT INTO tblcontacts (userid,firstname,lastname,email,subaccount,generalemails,productemails,domainemails,invoiceemails,supportemails) VALUES (1,'Contact','One','contact1@example.test',1,1,1,1,1,1)");
$pdo->exec("INSERT INTO tblproductgroups (name,hidden,`order`) VALUES ('Hosting',0,1)");
$pdo->exec("INSERT INTO tblproducts (gid,name,description,type,hidden,paytype,autosetup,servertype,tax,`order`,servergroup) VALUES (1,'Starter','Small plan','hosting',0,'recurring','on','cpanel',1,1,0)");
$pdo->exec("INSERT INTO tblproducts (gid,name,description,type,hidden,paytype,autosetup,servertype,tax,`order`,servergroup) VALUES (1,'Business','Bigger plan','hosting',0,'recurring','on','cpanel',1,2,0)");
// Product 1 is priced in a non-default currency. A shop-currency copy must be
// derived so the admin product form shows a price.
$pdo->exec("INSERT INTO tblpricing (type,relid,currency,msetupfee,monthly,quarterly,semiannually,annually,biennially,triennially) VALUES ('product',1,2,0.00,20.00,0.00,0.00,200.00,-1.00,-1.00)");
// Product 2 is already priced in the shop currency and must not be overwritten.
$pdo->exec("INSERT INTO tblpricing (type,relid,currency,msetupfee,monthly,quarterly,semiannually,annually,biennially,triennially) VALUES ('product',2,1,10.00,15.00,45.00,90.00,160.00,300.00,400.00)");
$pdo->exec("INSERT INTO tblhosting (userid,packageid,server,orderid,domain,username,qty,firstpaymentamount,amount,billingcycle,nextduedate,regdate,domainstatus,diskusage,disklimit,bwusage,bwlimit) VALUES (1,1,0,1,'example.test','user1',1,9.99,9.99,'Monthly','2026-12-01','2023-01-01','Active',100,1000,500,5000)");
$pdo->exec("INSERT INTO tblhosting (userid,packageid,server,orderid,domain,username,qty,firstpaymentamount,amount,billingcycle,nextduedate,regdate,domainstatus,diskusage,disklimit,bwusage,bwlimit) VALUES (1,2,0,1,'example.test','user2',1,19.99,19.99,'Monthly','2026-12-01','2023-01-01','Active',200,2000,600,6000)");
$pdo->exec("INSERT INTO tblhosting (userid,packageid,server,orderid,domain,username,qty,firstpaymentamount,amount,billingcycle,nextduedate,regdate,domainstatus,diskusage,disklimit,bwusage,bwlimit) VALUES (1,1,0,1,'','user3',1,9.99,9.99,'Monthly','2026-12-01','2023-01-01','Suspended',0,1000,0,5000)");
// Client 5 uses EUR; its service price must be converted to the shop currency
// while the original amount stays available for audit.
$pdo->exec("INSERT INTO tblhosting (userid,packageid,server,orderid,domain,username,qty,firstpaymentamount,amount,billingcycle,nextduedate,regdate,domainstatus,diskusage,disklimit,bwusage,bwlimit) VALUES (5,1,0,1,'five.test','user5',1,50.00,100.00,'Monthly','2026-12-01','2023-01-05','Active',100,1000,500,5000)");
$pdo->exec("INSERT INTO tblorders (ordernum,userid,`date`,amount,paymentmethod,invoiceid,status) VALUES ('WHMCS-1001',1,'2023-01-01',9.99,'paypal',1,'Active')");
$pdo->exec("INSERT INTO tblinvoices (userid,invoicenum,`date`,duedate,datepaid,subtotal,tax,total,taxrate,status,paymentmethod) VALUES (1,'INV-1001','2023-01-01','2023-01-15','2023-01-02 12:00:00',9.99,0.00,9.99,0.000,'Paid','paypal')");
// WHMCS leaves invoicenum blank when the operator uses the automatic number.
// The source row ID (2) is the displayed invoice number.
$pdo->exec("INSERT INTO tblinvoices (userid,invoicenum,`date`,duedate,datepaid,subtotal,tax,total,taxrate,status,paymentmethod) VALUES (5,'','2023-01-05','2023-01-20','2023-01-06 12:00:00',50.00,0.00,50.00,0.000,'Paid','bank')");
$pdo->exec("INSERT INTO tblinvoiceitems (invoiceid,userid,type,relid,description,amount,taxed,duedate) VALUES (1,1,'Hosting',1,'Starter plan - monthly',9.99,0,NULL)");
$pdo->exec("INSERT INTO tblaccounts (userid,invoiceid,gateway,`date`,description,amountin,amountout,fees,rate,transid,currency) VALUES (1,1,'paypal','2023-01-02','Invoice INV-1001',9.99,0,0.25,1.00000,'TXN-1001',1)");
$pdo->exec("INSERT INTO tblaccounts (userid,invoiceid,gateway,`date`,description,amountin,amountout,fees,rate,transid,currency) VALUES (5,0,'bank','2023-01-03','Company Five payment',50.00,0,0.00,1.00000,'TXN-1005',1)");
$pdo->exec("INSERT INTO tblaccounts (userid,invoiceid,gateway,`date`,description,amountin,amountout,fees,rate,transid,currency) VALUES (1,1,'manual','0000-00-00 00:00:00','Unknown date payment',1.00,0,0.00,1.00000,'TXN-1006',1)");
// Distinct payments sharing the same source transaction ID must not collapse.
$pdo->exec("INSERT INTO tblaccounts (userid,invoiceid,gateway,`date`,description,amountin,amountout,fees,rate,transid,currency) VALUES (1,1,'paypal','2023-01-04','Second payment with same reference',2.00,0,0.00,1.00000,'TXN-1001',1)");
$pdo->exec("INSERT INTO tblticketdepartments (name,description,email,clientsonly,hidden,`order`) VALUES ('Billing','Billing questions','billing@example.test','on','',1)");
$pdo->exec("INSERT INTO tblticketdepartments (name,description,email,clientsonly,hidden,`order`) VALUES ('Support','Technical help','support@example.test','','on',2)");
$pdo->exec("INSERT INTO tbltickets (tid,userid,did,name,email,title,message,status,urgency,admin,lastreply,`date`) VALUES ('ABC-123',1,1,'Client One','client1@example.test','Cannot login','Please help','Closed','High','admin','2023-01-03 10:00:00','2023-01-02 09:00:00')");
$pdo->exec("INSERT INTO tblticketreplies (tid,userid,contactid,message,admin,rating,`date`) VALUES (1,1,0,'Replies are working','admin',5,'2023-01-03 10:05:00')");
$pdo->exec("INSERT INTO tblticketreplies (tid,userid,contactid,message,admin,rating,`date`) VALUES (1,0,0,'','admin',0,'2023-01-03 10:06:00')");
$pdo->exec("INSERT INTO tblticketnotes (ticketid,admin,message,attachments,`date`) VALUES (1,'admin','Internal note only','','2023-01-03 10:10:00')");
$pdo->exec("INSERT INTO tbldomains (userid,orderid,domain,type,registrar,registrationperiod,registrationdate,expirydate,nextduedate,status,dnsmanagement,emailforwarding,idprotection,paymentmethod,firstpaymentamount,recurringamount) VALUES (1,1,'example.test','Register','ExampleRegistrar',1,'2023-01-01','2024-01-01','2024-01-01','Active',1,1,1,'paypal',10.00,10.00)");
$pdo->exec("INSERT INTO tblactivitylog (`date`,description,`user`,ipaddr) VALUES ('2023-01-01 10:00:00','Client created','admin','127.0.0.1')");

echo "Synthetic WHMCS source ready: $sourceDb\n";
