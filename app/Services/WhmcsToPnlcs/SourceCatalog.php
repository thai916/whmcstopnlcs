<?php

namespace App\Services\WhmcsToPnlcs;

/**
 * The migration catalogue: what WHMCS table feeds which PNLCS table, how the
 * fields line up, and whether the target is native or an archive-only record.
 *
 * Nothing here writes to WHMCS. `archive` entities are preserved verbatim in
 * the migration archive and never become operational PNLCS records.
 */
class SourceCatalog
{
    /** Dependency-aware import order. */
    public const ORDER = [
        // Foundations and identities first.
        'currencies', 'client_groups', 'clients', 'contacts', 'custom_fields',
        'ticket_departments', 'product_groups', 'server_groups', 'servers',
        'products', 'product_addons', 'pricing', 'config_option_groups',
        'config_options', 'config_option_subs', 'config_option_links',
        'server_group_links', 'promotions', 'affiliates',
        // Financial documents before the services/orders that point at them.
        'invoices', 'orders', 'services', 'domains', 'invoice_items',
        // Polymorphic values can now resolve their target entities.
        'custom_field_values', 'transactions', 'credits', 'cancellation_requests',
        'payment_methods', 'tickets', 'ticket_replies', 'ticket_notes',
        'client_notes', 'activity_logs', 'other_history',
    ];

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return [
            'currencies' => [
                'label' => 'Currencies', 'source' => 'currencies', 'target' => 'currencies',
                'mode' => 'native', 'batch' => 50,
                'fields' => [
                    'code' => 'code', 'prefix' => 'prefix', 'suffix' => 'suffix',
                    'format' => 'format', 'rate' => 'rate',
                ],
                'defaults' => ['is_default' => 0],
            ],
            'client_groups' => [
                'label' => 'Client groups', 'source' => 'clientgroups', 'target' => 'client_groups',
                'mode' => 'native', 'batch' => 50,
                'fields' => ['name' => 'groupname'],
            ],
            'clients' => [
                'label' => 'Clients', 'source' => 'clients', 'target' => 'clients',
                'mode' => 'native', 'batch' => 100,
                'fields' => [
                    'uuid' => null, 'first_name' => 'firstname', 'last_name' => 'lastname',
                    'company_name' => 'companyname', 'email' => 'email', 'address1' => 'address1',
                    'address2' => 'address2', 'city' => 'city', 'state' => 'state',
                    'postcode' => 'postcode', 'country' => 'country', 'phone_number' => 'phonenumber',
                    'tax_id' => 'tax_id', 'status' => 'status', 'language' => 'language',
                    'notes' => 'notes', 'credit' => 'credit', 'tax_exempt' => 'taxexempt',
                    'ip_address' => 'ip', 'created_at' => 'datecreated',
                ],
                'references' => ['group_id' => 'client_groups', 'currency_id' => 'currencies'],
                'source_refs' => ['group_id' => 'groupid', 'currency_id' => 'currency'],
                'defaults' => ['uuid' => null],
                'transforms' => ['status' => 'clientStatus'],
            ],
            'contacts' => [
                'label' => 'Contacts & subaccounts', 'source' => 'contacts', 'target' => 'contacts',
                'mode' => 'native', 'batch' => 100,
                'fields' => [
                    'first_name' => 'firstname', 'last_name' => 'lastname', 'email' => 'email',
                    'company_name' => 'companyname', 'address1' => 'address1', 'address2' => 'address2',
                    'city' => 'city', 'state' => 'state', 'postcode' => 'postcode',
                    'country' => 'country', 'phone_number' => 'phonenumber',
                    'is_sub_account' => 'subaccount', 'general_emails' => 'generalemails',
                    'product_emails' => 'productemails', 'domain_emails' => 'domainemails',
                    'invoice_emails' => 'invoiceemails', 'support_emails' => 'supportemails',
                ],
                'references' => ['client_id' => 'clients'],
                'source_refs' => ['client_id' => 'userid'],
                'defaults' => ['password' => null, 'permissions' => null],
                'no_login' => true,
            ],
            'custom_fields' => [
                'label' => 'Custom field definitions', 'source' => 'customfields', 'target' => 'custom_fields',
                'mode' => 'native', 'batch' => 100,
                'fields' => [
                    'type' => 'type', 'field_name' => 'fieldname', 'field_type' => 'fieldtype',
                    'description' => 'description', 'field_options' => 'fieldoptions',
                    'regex' => 'regexpr', 'admin_only' => 'adminonly', 'required' => 'required',
                    'sort_order' => 'sortorder', 'show_on_invoice' => 'showinvoice',
                    'show_on_order' => 'showorder',
                ],
            ],
            'custom_field_values' => [
                'label' => 'Custom field values', 'source' => 'customfieldsvalues', 'target' => 'custom_field_values',
                'mode' => 'native', 'batch' => 200,
                'fields' => ['value' => 'value'],
                'references' => ['field_id' => 'custom_fields'],
                'source_refs' => ['field_id' => 'fieldid'],
                'rel_id' => ['column' => 'relid', 'entities' => ['client' => 'clients', 'product' => 'products', 'service' => 'services', 'domain' => 'domains']],
            ],
            'ticket_departments' => [
                'label' => 'Ticket departments', 'source' => 'ticketdepartments', 'target' => 'ticket_departments',
                'mode' => 'native', 'batch' => 50,
                'fields' => [
                    'name' => 'name', 'description' => 'description', 'email' => 'email',
                    'clients_only' => 'clientsonly', 'hidden' => 'hidden', 'sort_order' => 'order',
                ],
                'transforms' => ['clients_only' => 'yesNoFlag', 'hidden' => 'yesNoFlag'],
            ],
            'product_groups' => [
                'label' => 'Product groups', 'source' => 'productgroups', 'target' => 'product_groups',
                'mode' => 'native', 'batch' => 50,
                'fields' => [
                    'name' => 'name', 'headline' => 'headline', 'tagline' => 'tagline',
                    'hidden' => 'hidden', 'sort_order' => 'order',
                ],
                'defaults' => ['slug' => null],
            ],
            'products' => [
                'label' => 'Products', 'source' => 'products', 'target' => 'products',
                'mode' => 'native', 'batch' => 50,
                'fields' => [
                    'name' => 'name', 'description' => 'description', 'type' => 'type',
                    'hidden' => 'hidden', 'pay_type' => 'paytype', 'auto_setup' => 'autosetup',
                    'server_type' => 'servertype', 'tax' => 'tax', 'sort_order' => 'order',
                    'welcome_email_template' => 'welcomeemail',
                ],
                'references' => ['group_id' => 'product_groups', 'server_group_id' => 'server_groups'],
                'source_refs' => ['group_id' => 'gid', 'server_group_id' => 'servergroup'],
                // Imported products stay non-orderable until a human reviews them,
                // but remain visible in the admin catalogue so they can be reviewed.
                'defaults' => [
                    'retired' => 1, 'hidden' => 0, 'slug' => null, 'auto_setup' => 'none',
                    'show_domain_options' => 0,
                ],
                'require_review' => true,
            ],
            'pricing' => [
                'label' => 'Product pricing', 'source' => 'pricing', 'target' => 'pricing',
                'mode' => 'native', 'batch' => 200,
                'fields' => [
                    'type' => 'type', 'monthly_setup' => 'msetupfee', 'quarterly_setup' => 'qsetupfee',
                    'semiannually_setup' => 'ssetupfee', 'annually_setup' => 'asetupfee',
                    'biennially_setup' => 'bsetupfee', 'triennially_setup' => 'tsetupfee',
                    'monthly' => 'monthly', 'quarterly' => 'quarterly',
                    'semiannually' => 'semiannually', 'annually' => 'annually',
                    'biennially' => 'biennially', 'triennially' => 'triennially',
                ],
                'references' => ['currency_id' => 'currencies'],
                'source_refs' => ['currency_id' => 'currency'],
                'rel_id' => ['column' => 'relid', 'entities' => [
                    'product' => 'products', 'addon' => 'product_addons', 'domain' => 'domain_pricing',
                ]],
            ],
            'product_addons' => [
                'label' => 'Product addons', 'source' => 'addons', 'target' => 'product_addons',
                'mode' => 'native', 'batch' => 50,
                'fields' => [
                    'name' => 'name', 'description' => 'description', 'hidden' => 'hidden',
                    'retired' => 'retired', 'sort_order' => 'weight', 'tax' => 'tax',
                ],
                'defaults' => ['hidden' => 1, 'retired' => 1, 'packages' => null],
            ],
            'config_option_groups' => [
                'label' => 'Configurable option groups', 'source' => 'productconfiggroups', 'target' => 'config_option_groups',
                'mode' => 'native', 'batch' => 50,
                'fields' => ['name' => 'name', 'description' => 'description'],
            ],
            'config_options' => [
                'label' => 'Configurable options', 'source' => 'productconfigoptions', 'target' => 'config_options',
                'mode' => 'native', 'batch' => 100,
                'fields' => [
                    'option_name' => 'optionname', 'option_type' => 'optiontype',
                    'qty_minimum' => 'qtyminimum', 'qty_maximum' => 'qtymaximum',
                    'sort_order' => 'order', 'hidden' => 'hidden',
                ],
                'references' => ['group_id' => 'config_option_groups'],
                'source_refs' => ['group_id' => 'gid'],
            ],
            'config_option_subs' => [
                'label' => 'Configurable option choices', 'source' => 'productconfigoptionssub', 'target' => 'config_option_subs',
                'mode' => 'native', 'batch' => 200,
                'fields' => ['option_name' => 'optionname', 'sort_order' => 'sortorder', 'hidden' => 'hidden'],
                'references' => ['config_id' => 'config_options'],
                'source_refs' => ['config_id' => 'configid'],
            ],
            'config_option_links' => [
                'label' => 'Configurable option links', 'source' => 'productconfiglinks', 'target' => 'config_option_links',
                'mode' => 'native', 'batch' => 200,
                'references' => ['group_id' => 'config_option_groups', 'product_id' => 'products'],
                'source_refs' => ['group_id' => 'gid', 'product_id' => 'pid'],
            ],
            'servers' => [
                'label' => 'Server metadata', 'source' => 'servers', 'target' => 'servers',
                'mode' => 'native', 'batch' => 50,
                'fields' => [
                    'name' => 'name', 'hostname' => 'hostname', 'ip_address' => 'ipaddress',
                    'max_accounts' => 'maxaccounts', 'type' => 'type', 'username' => 'username',
                    'port' => 'port', 'active' => 'active', 'nameserver1' => 'nameserver1',
                    'nameserver2' => 'nameserver2', 'nameserver3' => 'nameserver3',
                    'nameserver4' => 'nameserver4', 'nameserver5' => 'nameserver5',
                ],
                // API secrets are deliberately excluded: no live integration.
                'defaults' => ['password' => null, 'access_hash' => null, 'active' => 0, 'disabled' => 1],
                'require_review' => true,
            ],
            'server_groups' => [
                'label' => 'Server groups', 'source' => 'servergroups', 'target' => 'server_groups',
                'mode' => 'native', 'batch' => 50,
                'fields' => ['name' => 'name'],
            ],
            'server_group_links' => [
                'label' => 'Server group assignments', 'source' => 'servergroupsrel', 'target' => 'server_group_server',
                'mode' => 'native', 'batch' => 200,
                'references' => ['server_group_id' => 'server_groups', 'server_id' => 'servers'],
                'source_refs' => ['server_group_id' => 'groupid', 'server_id' => 'serverid'],
            ],
            'orders' => [
                'label' => 'Orders', 'source' => 'orders', 'target' => 'orders',
                'mode' => 'native', 'batch' => 100,
                'fields' => [
                    'order_num' => 'ordernum', 'date' => 'date', 'promo_code' => 'promocode',
                    'amount' => 'amount', 'payment_method' => 'paymentmethod', 'status' => 'status',
                    'ip_address' => 'ipaddress', 'fraud_module' => 'fraudmodule',
                    'fraud_output' => 'fraudoutput', 'notes' => 'notes',
                    'created_at' => 'date',
                ],
                'references' => ['client_id' => 'clients', 'invoice_id' => 'invoices'],
                'source_refs' => ['client_id' => 'userid', 'invoice_id' => 'invoiceid'],
                'defaults' => ['order_num' => null],
            ],
            'services' => [
                'label' => 'Hosting services', 'source' => 'hosting', 'target' => 'services',
                'mode' => 'native', 'batch' => 100,
                'fields' => [
                    'domain' => 'domain', 'username' => 'username',
                    'qty' => 'qty', 'first_payment_amount' => 'firstpaymentamount',
                    'amount' => 'amount', 'billing_cycle' => 'billingcycle',
                    'next_due_date' => 'nextduedate', 'registration_date' => 'regdate',
                    'status' => 'domainstatus', 'disk_usage' => 'diskusage', 'disk_limit' => 'disklimit',
                    'bw_usage' => 'bwusage', 'bw_limit' => 'bwlimit', 'notes' => 'notes',
                    'suspension_reason' => 'suspendreason',
                    'created_at' => 'regdate',
                ],
                'references' => [
                    'client_id' => 'clients', 'product_id' => 'products', 'server_id' => 'servers',
                    'order_id' => 'orders',
                ],
                'source_refs' => [
                    'client_id' => 'userid', 'product_id' => 'packageid', 'server_id' => 'server',
                    'order_id' => 'orderid',
                ],
                'transforms' => ['status' => 'serviceStatus', 'billing_cycle' => 'billingCycle'],
                'zero_dates' => ['next_due_date', 'registration_date', 'created_at'],
                'no_provisioning' => true,
            ],
            'domains' => [
                'label' => 'Domains', 'source' => 'domains', 'target' => 'domains',
                'mode' => 'native', 'batch' => 100,
                'fields' => [
                    'domain' => 'domain', 'type' => 'type', 'registrar' => 'registrar',
                    'registration_period' => 'registrationperiod', 'registration_date' => 'registrationdate',
                    'expiry_date' => 'expirydate', 'next_due_date' => 'nextduedate',
                    'status' => 'status', 'dns_management' => 'dnsmanagement',
                    'email_forwarding' => 'emailforwarding', 'id_protection' => 'idprotection',
                    'payment_method' => 'paymentmethod', 'first_payment_amount' => 'firstpaymentamount',
                    'recurring_amount' => 'recurringamount', 'notes' => 'additionalnotes',
                    'created_at' => 'registrationdate',
                ],
                'references' => ['client_id' => 'clients', 'order_id' => 'orders'],
                'source_refs' => ['client_id' => 'userid', 'order_id' => 'orderid'],
                'transforms' => ['status' => 'domainStatus'],
                'zero_dates' => ['registration_date', 'expiry_date', 'next_due_date'],
                'no_registrar' => true,
            ],
            'invoices' => [
                'label' => 'Invoices', 'source' => 'invoices', 'target' => 'invoices',
                'mode' => 'native', 'batch' => 100,
                'fields' => [
                    'invoice_num' => 'invoicenum', 'date' => 'date', 'due_date' => 'duedate',
                    'date_paid' => 'datepaid', 'subtotal' => 'subtotal', 'credit' => 'credit',
                    'tax' => 'tax', 'tax2' => 'tax2', 'total' => 'total',
                    'tax_rate' => 'taxrate', 'tax_rate2' => 'taxrate2', 'status' => 'status',
                    'payment_method' => 'paymentmethod', 'notes' => 'notes',
                    'created_at' => 'date',
                ],
                'references' => ['client_id' => 'clients'],
                'source_refs' => ['client_id' => 'userid'],
                'zero_dates' => ['date', 'due_date', 'date_paid', 'created_at'],
                'no_email' => true,
            ],
            'invoice_items' => [
                'label' => 'Invoice line items', 'source' => 'invoiceitems', 'target' => 'invoice_items',
                'mode' => 'native', 'batch' => 200,
                'fields' => [
                    'type' => 'type', 'description' => 'description', 'amount' => 'amount',
                    'taxed' => 'taxed', 'due_date' => 'duedate',
                ],
                'references' => ['invoice_id' => 'invoices', 'client_id' => 'clients'],
                'source_refs' => ['invoice_id' => 'invoiceid', 'client_id' => 'userid'],
                'rel_id' => ['column' => 'relid', 'entities' => [
                    'Hosting' => 'services', 'Domain' => 'domains', 'Addon' => 'product_addons',
                    'Item' => 'products', 'PromoHosting' => 'products', 'PromoDomain' => 'products',
                ], 'by_type' => 'type'],
                'zero_dates' => ['due_date'],
            ],
            'transactions' => [
                'label' => 'Transactions & payments', 'source' => 'accounts', 'target' => 'transactions',
                'mode' => 'native', 'batch' => 200,
                'fields' => [
                    'gateway' => 'gateway', 'date' => 'date', 'description' => 'description',
                    'amount_in' => 'amountin', 'amount_out' => 'amountout', 'fees' => 'fees',
                    'rate' => 'rate', 'transaction_id' => 'transid',
                    'created_at' => 'date',
                ],
                'references' => ['client_id' => 'clients', 'invoice_id' => 'invoices', 'currency_id' => 'currencies'],
                'source_refs' => ['client_id' => 'userid', 'invoice_id' => 'invoiceid', 'currency_id' => 'currency'],
                'zero_dates' => ['date', 'created_at'],
                'no_gateway' => true,
            ],
            'credits' => [
                'label' => 'Client credit history', 'source' => 'credit', 'target' => 'credits',
                'mode' => 'native', 'batch' => 200,
                'fields' => ['date' => 'date', 'description' => 'description', 'amount' => 'amount', 'created_at' => 'date'],
                'references' => ['client_id' => 'clients'],
                'source_refs' => ['client_id' => 'clientid'],
            ],
            'tickets' => [
                'label' => 'Support tickets', 'source' => 'tickets', 'target' => 'tickets',
                'mode' => 'native', 'batch' => 50,
                'fields' => [
                    'tid' => 'tid', 'name' => 'name', 'email' => 'email', 'cc' => 'cc',
                    'title' => 'title', 'message' => 'message', 'status' => 'status',
                    'priority' => 'urgency', 'admin' => 'admin', 'last_reply' => 'lastreply',
                    'created_at' => 'date',
                ],
                'references' => ['client_id' => 'clients', 'department_id' => 'ticket_departments'],
                'source_refs' => ['client_id' => 'userid', 'department_id' => 'did'],
                'transforms' => ['priority' => 'ticketPriority'],
                'defaults' => ['editor' => 'plain', 'attachment' => null],
                'zero_dates' => ['last_reply'],
                'no_email' => true,
            ],
            'ticket_replies' => [
                'label' => 'Ticket replies', 'source' => 'ticketreplies', 'target' => 'ticket_replies',
                'mode' => 'native', 'batch' => 100,
                'fields' => ['message' => 'message', 'admin' => 'admin', 'rating' => 'rating', 'created_at' => 'date'],
                'references' => ['ticket_id' => 'tickets', 'client_id' => 'clients', 'contact_id' => 'contacts'],
                'source_refs' => ['ticket_id' => 'tid', 'client_id' => 'userid', 'contact_id' => 'contactid'],
                'defaults' => ['editor' => 'plain', 'attachment' => null],
                'no_email' => true,
            ],
            'ticket_notes' => [
                'label' => 'Internal ticket notes', 'source' => 'ticketnotes', 'target' => 'ticket_notes',
                'mode' => 'native', 'batch' => 100,
                'fields' => ['admin' => 'admin', 'message' => 'message', 'attachments' => 'attachments', 'created_at' => 'date'],
                'references' => ['ticket_id' => 'tickets'],
                'source_refs' => ['ticket_id' => 'ticketid'],
                'defaults' => ['editor' => 'plain'],
                'private' => true,
            ],
            'client_notes' => [
                'label' => 'Client notes', 'source' => 'notes', 'target' => 'client_notes',
                'mode' => 'native', 'batch' => 100,
                'fields' => ['admin' => 'adminid', 'note' => 'note', 'sticky' => 'sticky', 'created_at' => 'created'],
                'references' => ['client_id' => 'clients'],
                'source_refs' => ['client_id' => 'userid'],
                'private' => true,
            ],
            'cancellation_requests' => [
                'label' => 'Cancellation requests', 'source' => 'cancelrequests', 'target' => 'cancellation_requests',
                'mode' => 'native', 'batch' => 100,
                'fields' => ['type' => 'type', 'reason' => 'reason'],
                'references' => ['service_id' => 'services'],
                'source_refs' => ['service_id' => 'relid'],
            ],
            'promotions' => [
                'label' => 'Promotions', 'source' => 'promotions', 'target' => 'promotions',
                'mode' => 'native', 'batch' => 50,
                'fields' => [
                    'code' => 'code', 'type' => 'type', 'recurring' => 'recurring',
                    'value' => 'value', 'cycles' => 'cycles', 'applies_to' => 'appliesto',
                    'requires' => 'requires', 'start_date' => 'startdate',
                    'expiration_date' => 'expirationdate', 'max_uses' => 'maxuses',
                    'uses' => 'uses', 'notes' => 'notes',
                ],
                'zero_dates' => ['start_date', 'expiration_date'],
            ],
            'affiliates' => [
                'label' => 'Affiliates', 'source' => 'affiliates', 'target' => 'affiliates',
                'mode' => 'native', 'batch' => 50,
                'fields' => [
                    'visitors' => 'visitors', 'pay_type' => 'paytype', 'pay_amount' => 'payamount',
                    'onetime' => 'onetime', 'balance' => 'balance', 'withdrawn' => 'withdrawn',
                ],
                'references' => ['client_id' => 'clients'],
                'source_refs' => ['client_id' => 'clientid'],
            ],
            'activity_logs' => [
                'label' => 'Activity history', 'source' => 'activitylog', 'target' => 'activity_logs',
                'mode' => 'archive', 'batch' => 200,
                'fields' => ['date' => 'date', 'description' => 'description', 'user' => 'user', 'ip_address' => 'ipaddr'],
            ],
            'payment_methods' => [
                'label' => 'Payment methods', 'source' => 'creditcards', 'target' => 'payment_methods',
                'mode' => 'archive', 'batch' => 100,
                'fields' => ['description' => 'card_type', 'gateway_name' => 'pay_method_id', 'last_four' => 'last_four', 'expiry_date' => 'expiry_date'],
                'reason' => 'Vaulted card tokens cannot be safely reused in PNLCS; metadata is archived only.',
            ],
            'other_history' => [
                'label' => 'Other discoverable history', 'source' => null, 'target' => null,
                'mode' => 'archive', 'batch' => 100,
                'reason' => 'Tables with no native PNLCS equivalent are stored verbatim in the read-only archive.',
            ],
        ];
    }

    public function get(string $entity): ?array
    {
        return $this->all()[$entity] ?? null;
    }

    /** Full source table name, if the entity has one. */
    public function sourceTable(string $entity, string $prefix): ?string
    {
        $definition = $this->get($entity);
        if (! $definition || ! $definition['source']) {
            return null;
        }

        return $prefix.$definition['source'];
    }

    /** Groups used by the wizard scope selector. */
    public function scopeGroups(): array
    {
        return [
            'full' => ['label' => 'Full migration', 'entities' => self::ORDER],
            'accounts' => ['label' => 'Clients & accounts', 'entities' => [
                'currencies', 'client_groups', 'clients', 'contacts', 'custom_fields',
                'custom_field_values', 'client_notes',
            ]],
            'catalogue' => ['label' => 'Products & services', 'entities' => [
                'product_groups', 'products', 'pricing', 'product_addons',
                'config_option_groups', 'config_options', 'config_option_subs',
                'config_option_links', 'servers', 'server_groups', 'server_group_links',
                'orders', 'services', 'domains', 'cancellation_requests',
            ]],
            'financial' => ['label' => 'Financial history', 'entities' => [
                'currencies', 'orders', 'invoices', 'invoice_items', 'transactions',
                'credits', 'promotions', 'affiliates',
            ]],
            'support' => ['label' => 'Support history', 'entities' => [
                'clients', 'ticket_departments', 'tickets', 'ticket_replies',
                'ticket_notes', 'activity_logs',
            ]],
        ];
    }
}
