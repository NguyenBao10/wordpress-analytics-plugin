<?php

namespace AesirxAnalytics;

use WP_Error;

if (!class_exists('AesirxAnalyticsMysqlHelper')) {
    Class AesirxAnalyticsMysqlHelper
    {
        public function aesirx_analytics_get_list($sql, $total_sql, $params, $allowed, $bind) {
            global $wpdb;
    
            $page = $params['page'] ?? 1;
            $pageSize = $params['page_size'] ?? 20;
            $skip = ($page - 1) * $pageSize;
        
            $sql .= " LIMIT " . $skip . ", " . $pageSize;
    
            $sql = str_replace("#__", $wpdb->prefix, $sql);
            $total_sql = str_replace("#__", $wpdb->prefix, $total_sql);
    
            // used placeholders in variable $sql and $bind
            $sql = $wpdb->prepare($sql, $bind);
            // used placeholders in variable $total_sql and $bind
            $total_sql = $wpdb->prepare($total_sql, $bind);
    
            $total_elements = (int) $wpdb->get_var($total_sql);
            $total_pages = ceil($total_elements / $pageSize);
    
            try {
                // used placeholders and $wpdb->prepare() in variable $sql
                $collection = $wpdb->get_results($sql, ARRAY_A);
    
                $list_response = [
                    'collection' => $collection,
                    'page' => (int) $page,
                    'page_size' => (int) $pageSize,
                    'total_pages' => $total_pages,
                    'total_elements' => $total_elements,
                ];
    
                if ($params[1] == "metrics") {
                    $list_response = $list_response['collection'][0];
                }
    
                return $list_response;
    
            } catch (Exception $e) {
                error_log("Query error: " . $e->getMessage());
                return new WP_Error('database_error', esc_html__('Database error occurred.', 'aesirx-analytics'), ['status' => 500]);
            }
        }
    
        public function aesirx_analytics_get_statistics_per_field($groups = [], $selects = [], $params = []) {
            global $wpdb;
    
            $select = [
                "coalesce(COUNT(DISTINCT (#__analytics_events.visitor_uuid)), 0) as number_of_visitors",
                "coalesce(COUNT(#__analytics_events.visitor_uuid), 0) as total_number_of_visitors",
                "COUNT(#__analytics_events.uuid) as number_of_page_views",
                "COUNT(DISTINCT (#__analytics_events.url)) AS number_of_unique_page_views",
                "coalesce(SUM(TIMESTAMPDIFF(SECOND, #__analytics_events.start, #__analytics_events.end)) / count(distinct #__analytics_visitors.uuid), 0) DIV 1 as average_session_duration",
                "coalesce((COUNT(#__analytics_events.uuid) / COUNT(DISTINCT (#__analytics_events.flow_uuid))), 0) DIV 1 as average_number_of_pages_per_session",
                "coalesce((count(DISTINCT CASE WHEN #__analytics_flows.multiple_events = 0 THEN #__analytics_flows.uuid END) * 100) / count(DISTINCT (#__analytics_flows.uuid)), 0) DIV 1 as bounce_rate",
            ];
    
            $total_select = [];
    
            if (!empty($groups)) {
                foreach ($groups as $one_group) {
                    $select[] = $one_group;
                }
    
                $total_select[] = "COUNT(DISTINCT " . implode(', COALESCE(', $groups) . ") AS total";
            }
            else {
                $total_select[] = "COUNT(#__analytics_events.uuid) AS total";
            }
    
            foreach ($selects as $additional_result) {
                $select[] = $additional_result["select"] . " AS " . $additional_result["result"];
            }
    
            $where_clause = [
                "#__analytics_events.event_name = %s",
                "#__analytics_events.event_type = %s",
            ];
    
            $bind = [
                'visit',
                'action'
            ];
    
            self::aesirx_analytics_add_filters($params, $where_clause, $bind);
    
            $acquisition = false;
            foreach ($params['filter'] as $key => $vals) {
                if ($key === "acquisition") {
                    $list = is_array($vals) ? $vals : [$vals];
                    if ($list[0] === "true") {
                        $acquisition = true;
                    }
                    break;
                }
            }
    
            if ($acquisition) {
                $where_clause[] = "#__analytics_flows.multiple_events = %d";
                $bind[] = 0;
            }
    
            $total_sql = "SELECT " . implode(", ", $total_select) . " FROM #__analytics_events
                        LEFT JOIN #__analytics_visitors ON #__analytics_visitors.uuid = #__analytics_events.visitor_uuid
                        LEFT JOIN #__analytics_flows ON #__analytics_flows.uuid = #__analytics_events.flow_uuid
                        WHERE " . implode(" AND ", $where_clause);
    
            $sql = "SELECT " . implode(", ", $select) . " FROM #__analytics_events
                    LEFT JOIN #__analytics_visitors ON #__analytics_visitors.uuid = #__analytics_events.visitor_uuid
                    LEFT JOIN #__analytics_flows ON #__analytics_flows.uuid = #__analytics_events.flow_uuid
                    WHERE " . implode(" AND ", $where_clause);
    
            if (!empty($groups)) {
                $sql .= " GROUP BY " . implode(", ", $groups);
            }
    
            $allowed = [
                "number_of_visitors",
                "number_of_page_views",
                "number_of_unique_page_views",
                "average_session_duration",
                "average_number_of_pages_per_session",
                "bounce_rate",
            ];
            $default = reset($allowed);
    
            foreach ($groups as $one_group) {
                $allowed[] = $one_group;
                $default = $one_group;
            }
    
            foreach ($groups as $additional_result) {
                $allowed[] = $additional_result;
            }
    
            $sort = self::aesirx_analytics_add_sort($params, $allowed, $default);
    
            if (!empty($sort)) {
                $sql .= " ORDER BY " . implode(", ", $sort);
            }
    
            return self::aesirx_analytics_get_list($sql, $total_sql, $params, $allowed, $bind);
        }
    
        function aesirx_analytics_add_sort($params, $allowed, $default) {
            $ret = [];
            $dirs = [];
    
            if (isset($params['sort_direction'])) {
                foreach ($params['sort_direction'] as $pos => $value) {
                    $dirs[$pos] = $value;
                }
            }
    
            if (!isset($params['sort'])) {
                $ret[] = sprintf("%s ASC", $default);
            } else {
                foreach ($params['sort'] as $pos => $value) {
                    if (!in_array($value, $allowed)) {
                        continue;
                    }
    
                    $dir = "ASC";
                    if (isset($dirs[$pos]) && $dirs[$pos] === "desc") {
                        $dir = "DESC";
                    }
    
                    $ret[] = sprintf("%s %s", $value, $dir);
                }
    
                if (empty($ret)) {
                    $ret[] = sprintf("%s ASC", $default);
                }
            }
    
            return $ret;
        }
    
        function aesirx_analytics_add_filters($params, &$where_clause, &$bind) {
            foreach ([$params['filter'], $params['filter_not']] as $filter_array) {
                $is_not = $filter_array === $params['filter_not'];
                if (empty($filter_array)) {
                    continue;
                }
        
                foreach ($filter_array as $key => $vals) {
                    $list = is_array($vals) ? $vals : [$vals];
    
                    switch ($key) {
                        case 'start':
                            try {
                                $date = gmdate("Y-m-d", strtotime($list[0]));
                                $where_clause[] = "#__analytics_events." . $key . " >= %s";
                                $bind[] = $date;
                            } catch (Exception $e) {
                                error_log('Validation error: ' . $e->getMessage());
                                return new WP_Error('validation_error', esc_html__('"start" filter is not correct', 'aesirx-analytics'), ['status' => 400]);
                            }
                            break;
                        case 'end':
                            try {
                                $date = gmdate("Y-m-d", strtotime($list[0] . ' +1 day'));
                                $where_clause[] = "#__analytics_events." . $key . " < %s";
                                $bind[] = $date;
                            } catch (Exception $e) {
                                error_log('Validation error: ' . $e->getMessage());
                                return new WP_Error('validation_error', esc_html__('"end" filter is not correct', 'aesirx-analytics'), ['status' => 400]);
                            }
                            break;
                        case 'event_name':
                        case 'event_type':
                            $where_clause[] = '#__analytics_events.' . $key . ' ' . ($is_not ? 'NOT ' : '') . 'IN (%s)';
                            $bind[] = implode(', ', $list);
                            break;
                        case 'city':
                        case 'isp':
                        case 'country_code':
                        case 'country_name':
                        case 'url':
                        case 'domain':
                        case 'browser_name':
                        case 'browser_version':
                        case 'device':
                        case 'lang':
                            $where_clause[] = '#__analytics_visitors.' . $key . ' ' . ($is_not ? 'NOT ' : '') . 'IN (%s)';
                            $bind[] = implode(', ', $list);
                            break;
                        default:
                            break;
                    }
                }
            }
        }
    
        function aesirx_analytics_add_attribute_filters($params, &$where_clause, &$bind) {
            foreach ([$params['filter'], $params['filter_not']] as $filter_array) {
                $is_not = $filter_array === $params['filter_not'];
                if (empty($filter_array)) {
                    continue;
                }
        
                foreach ($filter_array as $key => $val) {
                    $list = is_array($val) ? $val : [$val];
                    switch ($key) {
                        case "attribute_name":
                            if ($is_not) {
                                $where_clause[] = '#__analytics_event_attributes.event_uuid IS NULL 
                                    OR #__analytics_event_attributes.name NOT IN (%s)';
                                $bind[] = implode(', ', $list);
                            } else {
                                $where_clause[] = '#__analytics_event_attributes.name IN (%s)';
                                $bind[] = implode(', ', $list);
                            }
                            break;
                        case "attribute_value":
                            if ($is_not) {
                                $where_clause[] = '#__analytics_event_attributes.event_uuid IS NULL 
                                    OR #__analytics_event_attributes.value NOT IN (%s)';
                                $bind[] = implode(', ', $list);
                            } else {
                                $where_clause[] = '#__analytics_event_attributes.value IN (%s)';
                                $bind[] = implode(', ', $list);
                            }
                            break;
                        default:
                            break;
                    }
                }
            }
        }
    
        function aesirx_analytics_validate_domain($url) {
            $parsed_url = wp_parse_url($url);
    
            if ($parsed_url === false || !isset($parsed_url['host'])) {
                return new WP_Error('validation_error', esc_html__('Domain not found', 'aesirx-analytics'), ['status' => 400]);
            }
    
            $domain = $parsed_url['host'];
    
            if (strpos($domain, 'www.') === 0) {
                $domain = substr($domain, 4);
            }

            return $domain;
        }
    
        function aesirx_analytics_find_visitor_by_fingerprint_and_domain($fingerprint, $domain) {
            global $wpdb;
    
            // Query to fetch the visitor
            try {
                $visitor = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT * 
                        FROM {$wpdb->prefix}analytics_visitors
                        WHERE fingerprint = %s AND domain = %s", 
                        sanitize_text_field($fingerprint), sanitize_text_field($domain))
                );
    
                if ($visitor) {
                    $res = [
                        'fingerprint' => $visitor->fingerprint,
                        'uuid' => $visitor->uuid,
                        'ip' => $visitor->ip,
                        'user_agent' => $visitor->user_agent,
                        'device' => $visitor->device,
                        'browser_name' => $visitor->browser_name,
                        'browser_version' => $visitor->browser_version,
                        'domain' => $visitor->domain,
                        'lang' => $visitor->lang,
                        'visitor_flows' => null,
                        'geo' => null,
                        'visitor_consents' => [],
                    ];
    
                    if ($visitor->geo_created_at) {
                        $res['geo'] = [
                            'country' => [
                                'name' => $visitor->country_name,
                                'code' => $visitor->country_code,
                            ],
                            'city' => $visitor->city,
                            'region' => $visitor->region,
                            'isp' => $visitor->isp,
                            'created_at' => $visitor->geo_created_at,
                        ];
                    }
    
                    // Query to fetch the visitor flows
                    $flows = $wpdb->get_results(
                        $wpdb->prepare("SELECT * FROM {$wpdb->prefix}analytics_flows WHERE visitor_uuid = %s ORDER BY id", sanitize_text_field($visitor->uuid))
                    );
    
                    if ($flows) {
                        $ret_flows = [];
                        foreach ($flows as $flow) {
                            $ret_flows[] = [
                                'uuid' => $flow->uuid,
                                'start' => $flow->start,
                                'end' => $flow->end,
                                'multiple_events' => $flow->multiple_events,
                            ];
                        }
                        $res['visitor_flows'] = $ret_flows;
                    }
    
                    return $res;
                }
    
                return null;
            } catch (Exception $e) {
                error_log('Query error: ' . $e->getMessage());
                return new WP_Error('db_query_error', esc_html__('There was a problem with the database query.', 'aesirx-analytics'), ['status' => 500]);
            }
        }
    
        function aesirx_analytics_create_visitor($visitor) {
            global $wpdb;
    
            try {
                if (empty($visitor['geo'])) {
                    $wpdb->insert(
                        $wpdb->prefix . 'analytics_visitors',
                        [
                            'fingerprint'      => sanitize_text_field($visitor['fingerprint']),
                            'uuid'             => sanitize_text_field($visitor['uuid']),
                            'ip'               => sanitize_text_field($visitor['ip']),
                            'user_agent'       => sanitize_text_field($visitor['user_agent']),
                            'device'           => sanitize_text_field($visitor['device']),
                            'browser_name'     => sanitize_text_field($visitor['browser_name']),
                            'browser_version'  => sanitize_text_field($visitor['browser_version']),
                            'domain'           => sanitize_text_field($visitor['domain']),
                            'lang'             => sanitize_text_field($visitor['lang'])
                        ],
                        [
                            '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'
                        ]
                    );
                } else {
                    $geo = $visitor['geo'];
                    $wpdb->insert(
                        $wpdb->prefix . 'analytics_visitors',
                        [
                            'fingerprint'      => sanitize_text_field($visitor['fingerprint']),
                            'uuid'             => sanitize_text_field($visitor['uuid']),
                            'ip'               => sanitize_text_field($visitor['ip']),
                            'user_agent'       => sanitize_text_field($visitor['user_agent']),
                            'device'           => sanitize_text_field($visitor['device']),
                            'browser_name'     => sanitize_text_field($visitor['browser_name']),
                            'browser_version'  => sanitize_text_field($visitor['browser_version']),
                            'domain'           => sanitize_text_field($visitor['domain']),
                            'lang'             => sanitize_text_field($visitor['lang']),
                            'country_code'     => sanitize_text_field($geo['country']['code']),
                            'country_name'     => sanitize_text_field($geo['country']['name']),
                            'city'             => sanitize_text_field($geo['city']),
                            'isp'              => sanitize_text_field($geo['isp']),
                            'geo_created_at'   => sanitize_text_field($geo['created_at'])
                        ],
                        [
                            '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'
                        ]
                    );
                }
        
                if (!empty($visitor['visitor_flows'])) {
                    foreach ($visitor['visitor_flows'] as $flow) {
                        self::aesirx_analytics_create_visitor_flow($visitor['uuid'], $flow);
                    }
                }
        
                return true;
            } catch (Exception $e) {
                error_log('Query error: ' . $e->getMessage());
                return new WP_Error('db_query_error', esc_html__('There was a problem with the database query.', 'aesirx-analytics'), ['status' => 500]);
            }
        }
    
        function aesirx_analytics_create_visitor_event($visitor_event) {
            global $wpdb;
    
            try {
                // Insert event
                $wpdb->insert(
                    $wpdb->prefix . 'analytics_events',
                    [
                        'uuid'         => sanitize_text_field($visitor_event['uuid']),
                        'visitor_uuid' => sanitize_text_field($visitor_event['visitor_uuid']),
                        'flow_uuid'    => sanitize_text_field($visitor_event['flow_uuid']),
                        'url'          => sanitize_text_field($visitor_event['url']),
                        'referer'      => sanitize_text_field($visitor_event['referer']),
                        'start'        => sanitize_text_field($visitor_event['start']),
                        'end'          => sanitize_text_field($visitor_event['end']),
                        'event_name'   => sanitize_text_field($visitor_event['event_name']),
                        'event_type'   => sanitize_text_field($visitor_event['event_type'])
                    ],
                    [
                        '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'
                    ]
                );
    
                // Insert event attributes
                if (!empty($visitor_event['attributes'])) {
                    $values = [];
                    $placeholders = [];
    
                    foreach ($visitor_event['attributes'] as $attribute) {
                        $wpdb->insert(
                            $wpdb->prefix . 'analytics_event_attributes',
                            array(
                                'event_uuid' => $visitor_event->uuid,
                                'name'       => $attribute->name,
                                'value'      => $attribute->value
                            ),
                            array(
                                '%s',
                                '%s',
                                '%s'
                            )
                        );
                    }     
                }
    
                return true;
            } catch (Exception $e) {
                error_log('Query error: ' . $e->getMessage());
                return new WP_Error('db_query_error', esc_html__('There was a problem with the database query.', 'aesirx-analytics'), ['status' => 500]);
            }
        }
    
        function aesirx_analytics_create_visitor_flow($visitor_uuid, $visitor_flow) {
            global $wpdb;
    
            try {
                $wpdb->insert(
                    $wpdb->prefix . 'analytics_flows',
                    [
                        'visitor_uuid'    => sanitize_text_field($visitor_uuid),
                        'uuid'            => sanitize_text_field($visitor_flow['uuid']),
                        'start'           => sanitize_text_field($visitor_flow['start']),
                        'end'             => sanitize_text_field($visitor_flow['end']),
                        'multiple_events' => sanitize_text_field($visitor_flow['multiple_events']) ? 1 : 0
                    ],
                    [
                        '%s', '%s', '%s', '%s', '%d'
                    ]
                );
        
                return true;
            } catch (Exception $e) {
                error_log('Query error: ' . $e->getMessage());
                return new WP_Error('db_query_error', esc_html__('There was a problem with the database query.', 'aesirx-analytics'), ['status' => 500]);
            }
        }
    
        function aesirx_analytics_mark_visitor_flow_as_multiple($visitor_flow_uuid) {
            global $wpdb;
    
            // Ensure UUID is properly formatted for the database
            $uuid_str = (string) $uuid;
    
            // need $wpdb->query() due to the complexity of the JSON manipulation required
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}visitor
                    SET visitor_flows = JSON_SET(visitor_flows, CONCAT('$[', JSON_UNQUOTE(JSON_SEARCH(visitor_flows, 'one', %s)), '].multiple_events'), true)
                    WHERE JSON_CONTAINS(visitor_flows, JSON_OBJECT('uuid', %s))",
                    sanitize_text_field($uuid_str), sanitize_text_field($uuid_str)
                )
            );
    
            if ($wpdb->last_error) {
                error_log('Query error: ' . $wpdb->last_error);
                return new WP_Error('db_query_error', esc_html__('There was a problem with the database query.', 'aesirx-analytics'), ['status' => 500]);
            }
        }
    
        function aesirx_analytics_add_consent_filters($params, &$where_clause, &$bind) {
            foreach ([$params['filter'], $params['filter_not']] as $filter_array) {
                $is_not = $filter_array === $params['filter_not'];
                if (empty($filter_array)) {
                    continue;
                }
    
                foreach ($filter_array as $key => $vals) {
                    $list = is_array($vals) ? $vals : [$vals];
    
                    switch ($key) {
                        case 'start':
                            try {
                                $date = gmdate("Y-m-d", strtotime($list[0]));
                                $where_clause[] = "#__analytics_visitor_consent.datetime >= %s";
                                $bind[] = $date;
                            } catch (Exception $e) {
                                error_log('Validation error: ' . $e->getMessage());
                                return new WP_Error('validation_error', esc_html__('"start" filter is not correct', 'aesirx-analytics'), ['status' => 400]);
                            }
                            break;
                        case 'end':
                            try {
                                $date = gmdate("Y-m-d", strtotime($list[0] . ' +1 day'));
                                $where_clause[] = "#__analytics_visitor_consent.datetime < %s";
                                $bind[] = $date;
                            } catch (Exception $e) {
                                error_log('Validation error: ' . $e->getMessage());
                                return new WP_Error('validation_error', esc_html__('"end" filter is not correct', 'aesirx-analytics'), ['status' => 400]);
                            }
                            break;
                        case 'domain':
                            $where_clause[] = 'visitors ' . ($is_not ? 'NOT ' : '') . 'IN (%s)';
                            $bind[] = implode(', ', $list);
                            break;
                        default:
                            break;
                    }
                }
            }
        }
    
        function aesirx_analytics_add_conversion_filters($params, &$where_clause, &$bind) {
            foreach ($params as $key => $vals) {
                $list = is_array($vals) ? $vals : [$vals];
    
                switch ($key) {
                    case 'start':
                        try {
                            $date = gmdate("Y-m-d", strtotime($list[0]));
                            $where_clause[] = "#__analytics_flows." . $key . " >= %s";
                            $bind[] = $date;
                        } catch (Exception $e) {
                            error_log('Validation error: ' . $e->getMessage());
                            return new WP_Error('validation_error', esc_html__('"start" filter is not correct', 'aesirx-analytics'), ['status' => 400]);
                        }
                        break;
                    case 'end':
                        try {
                            $date = gmdate("Y-m-d", strtotime($list[0] . ' +1 day'));
                            $where_clause[] = "#__analytics_flows." . $key . " < %s";
                            $bind[] = $date;
                        } catch (Exception $e) {
                            error_log('Validation error: ' . $e->getMessage());
                            return new WP_Error('validation_error', esc_html__('"end" filter is not correct', 'aesirx-analytics'), ['status' => 400]);
                        }
                        break;
                    default:
                        break;
                }
            }
        }
    
        function aesirx_analytics_find_wallet($network, $address) {
            global $wpdb;

            $wallet = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}analytics_wallet WHERE network = %s AND address = %s",
                    sanitize_text_field($network), sanitize_text_field($address)
                )
            );
    
            if ($wpdb->last_error) {
                error_log('Query error: ' . $wpdb->last_error);
                return new WP_Error('db_query_error', esc_html__('There was a problem with the database query.', 'aesirx-analytics'), ['status' => 500]);
            }
    
            return $wallet;
        }
    
        function aesirx_analytics_add_wallet($uuid, $network, $address, $nonce) {
            global $wpdb;
    
            $wpdb->insert(
                $wpdb->prefix . 'analytics_wallet',
                [
                    'uuid'     => sanitize_text_field($uuid),
                    'network'  => sanitize_text_field($network),
                    'address'  => sanitize_text_field($address),
                    'nonce'    => sanitize_text_field($nonce)
                ],
                [
                    '%s', '%s', '%s', '%s'
                ]
            );
    
            if ($wpdb->last_error) {
                error_log('Query error: ' . $wpdb->last_error);
                return new WP_Error('db_query_error', esc_html__('There was a problem with the database query.', 'aesirx-analytics'), ['status' => 500]);
            }
        }
    
        function aesirx_analytics_update_nonce($network, $address, $nonce) {
            global $wpdb;

            $wpdb->update(
                $wpdb->prefix . 'analytics_wallet',
                array(
                    'nonce' => sanitize_text_field($nonce)
                ),
                array(
                    'network' => sanitize_text_field($network),
                    'address' => sanitize_text_field($address)
                ),
                array(
                    '%s'  // Data type for 'nonce'
                ),
                array(
                    '%s', // Data type for 'network'
                    '%s'  // Data type for 'address'
                )
            );
    
            if ($wpdb->last_error) {
                error_log('Query error: ' . $wpdb->last_error);
                return new WP_Error('db_query_error', esc_html__('There was a problem with the database query.', 'aesirx-analytics'), ['status' => 500]);
            }
        }
    
        function aesirx_analytics_add_consent($uuid, $consent, $datetime, $web3id = null, $wallet_uuid = null, $expiration = null) {
            global $wpdb;
    
            $data = array(
                'uuid'      => sanitize_text_field($uuid),
                'consent'   => sanitize_text_field($consent),
                'datetime'  => $datetime
            );
            
            // Conditionally add wallet_uuid
            if (!empty($wallet_uuid)) {
                $data['wallet_uuid'] = sanitize_text_field($wallet_uuid);
            }
            
            // Conditionally add web3id
            if (!empty($web3id)) {
                $data['web3id'] = sanitize_text_field($web3id);
            }
            
            // Conditionally add expiration
            if (!empty($expiration)) {
                $data['expiration'] = $expiration;
            }
            
            // Prepare the data types based on the keys
            $data_types = array_fill(0, count($data), '%s'); // Adjust the types as needed
            
            // Execute the insert
            $wpdb->insert(
                $wpdb->prefix . 'analytics_consent',
                $data,
                $data_types
            );
    
            if ($wpdb->last_error) {
                error_log('Query error: ' . $wpdb->last_error);
                return new WP_Error('db_query_error', esc_html__('There was a problem with the database query.', 'aesirx-analytics'), ['status' => 500]);
            }
        }
    
        function aesirx_analytics_add_visitor_consent($visitor_uuid, $consent_uuid = null, $consent = null, $datetime = null, $expiration = null) {
            global $wpdb;
    
            $data = array(
                'uuid'         => wp_generate_uuid4(),
                'visitor_uuid' => $visitor_uuid,
            );
            
            // Conditionally add consent_uuid
            if (!empty($consent_uuid)) {
                $data['consent_uuid'] = $consent_uuid;
            }
            
            // Conditionally add consent
            if (!empty($consent)) {
                $data['consent'] = intval($consent);
            }
            
            // Conditionally add datetime
            if (!empty($datetime)) {
                $data['datetime'] = $datetime;
            }
            
            // Conditionally add expiration
            if (!empty($expiration)) {
                $data['expiration'] = $expiration;
            }
            
            // Prepare the data types based on the keys
            $data_types = array_fill(0, count($data), '%s'); // Default to '%s' for all
            
            if (isset($data['consent'])) {
                $data_types[array_search('consent', array_keys($data))] = '%d'; // Change to '%d' if consent is an integer
            }
            
            // Execute the insert
            $wpdb->insert(
                $wpdb->prefix . 'analytics_visitor_consent',
                $data,
                $data_types
            );
    
            if ($wpdb->last_error) {
                error_log('Query error: ' . $wpdb->last_error);
                return new WP_Error('db_insert_error', esc_html__('Could not insert consent', 'aesirx-analytics'), ['status' => 500]);
            }
    
            return true;
        }
    
        function aesirx_analytics_group_consents_by_domains($consents) {
            $consent_domain_list = [];
    
            foreach ($consents as $consent) {
                if (empty($consent['visitor'])) {
                    continue;
                }
    
                $visitor_domain = isset($consent['visitor'][0]['domain']) ? $consent['visitor'][0]['domain'] : null;
                if ($visitor_domain === null) {
                    continue;
                }
    
                if (!isset($consent_domain_list[$visitor_domain])) {
                    $consent_domain_list[$visitor_domain] = [];
                }
    
                $consent_domain_list[$visitor_domain][] = [
                    'uuid' => $consent['uuid'],
                    'wallet_uuid' => $consent['wallet_uuid'],
                    'address' => $consent['address'],
                    'network' => $consent['network'],
                    'web3id' => $consent['web3id'],
                    'consent' => $consent['consent'],
                    'datetime' => $consent['datetime'],
                    'expiration' => $consent['expiration']
                ];
            }
    
            $consents_by_domain = [];
    
            foreach ($consent_domain_list as $domain => $domain_consents) {
                $consents_by_domain[] = [
                    'domain' => $domain,
                    'consents' => $domain_consents
                ];
            }
    
            return $consents_by_domain;
        }
    
        function aesirx_analytics_validate_string($nonce, $wallet, $singnature) {

            $api_url = 'http://dev01.aesirx.io:8888/validate/string?nonce=' 
            . sanitize_text_field($nonce) . '&wallet=' 
            . sanitize_text_field($wallet) . '&signature=' 
            . sanitize_text_field($singnature);
            $response = wp_remote_get($api_url, array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                ),
            ));

            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                error_log('API error: ' . $error_message);
                return new WP_Error('validation_error', esc_html__('Something went wrong', 'aesirx-analytics'));
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            return $data;
        }

        function aesirx_analytics_validate_address($wallet) {
            $api_url = 'http://dev01.aesirx.io:8888/validate/wallet?wallet=' . sanitize_text_field($wallet);
            $response = wp_remote_get($api_url, array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                ),
            ));

            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                error_log('API error: ' . $error_message);
                return new WP_Error('validation_error', esc_html__('Something went wrong', 'aesirx-analytics'));
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            return $data;
        }

        function aesirx_analytics_validate_contract($token) {
            $api_url = 'http://dev01.aesirx.io:8888/validate/contract';
            $response = wp_remote_get($api_url, array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . sanitize_text_field($token),
                ),
            ));

            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                error_log('API error: ' . $error_message);
                return new WP_Error('validation_error', esc_html__('Something went wrong', 'aesirx-analytics'));
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            return $data;
        }
    
        function aesirx_analytics_expired_consent($consent_uuid, $expiration) {
            global $wpdb;
    
            try {
                // Format the expiration date if it is set
                $data = array(
                    'expiration' => $expiration ? $expiration : null,
                );
                
                $where = array(
                    'uuid' => sanitize_text_field($consent_uuid),
                );
                
                // Execute the update
                $wpdb->update(
                    $wpdb->prefix . 'analytics_consent',
                    $data,
                    $where,
                    array('%s'),  // Data type for 'expiration'
                    array('%s')   // Data type for 'uuid'
                );

                if ($wpdb->last_error) {
                    error_log('Query error: ' . $wpdb->last_error);
                    return new WP_Error($wpdb->last_error);
                }
                return true;
            } catch (Exception $e) {
                error_log("Query error: " . $e->getMessage());
                return new WP_Error('db_update_error', esc_html__('There was a problem updating the data in the database.', 'aesirx-analytics'), ['status' => 500]);
            }
        }
    
        function aesirx_analytics_find_visitor_by_uuid($uuid) {
            global $wpdb;

            try {
                // Execute the queries
                $visitor_result = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}analytics_visitors WHERE uuid = %s",
                        sanitize_text_field($uuid)
                    )
                );
                $flows_result = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}analytics_flows WHERE visitor_uuid = %s ORDER BY id",
                        sanitize_text_field($uuid)
                    )
                );
    
                if ($visitor_result) {
                    // Create the visitor object
                    $visitor = (object)[
                        'fingerprint' => $visitor_result->fingerprint,
                        'uuid' => $visitor_result->uuid,
                        'ip' => $visitor_result->ip,
                        'user_agent' => $visitor_result->user_agent,
                        'device' => $visitor_result->device,
                        'browser_name' => $visitor_result->browser_name,
                        'browser_version' => $visitor_result->browser_version,
                        'domain' => $visitor_result->domain,
                        'lang' => $visitor_result->lang,
                        'visitor_flows' => null,
                        'geo' => null,
                        'visitor_consents' => [],
                    ];
    
                    if ($visitor_result->geo_created_at) {
                        $visitor->geo = (object)[
                            'country' => (object)[
                                'name' => $visitor_result->country_name,
                                'code' => $visitor_result->country_code,
                            ],
                            'city' => $visitor_result->city,
                            'region' => $visitor_result->region,
                            'isp' => $visitor_result->isp,
                            'created_at' => $visitor_result->geo_created_at,
                        ];
                    }
    
                    if (!empty($flows_result)) {
                        $visitor_flows = array_map(function($flow) {
                            return (object)[
                                'uuid' => $flow->uuid,
                                'start' => $flow->start,
                                'end' => $flow->end,
                                'multiple_events' => $flow->multiple_events,
                            ];
                        }, $flows_result);
    
                        $visitor->visitor_flows = $visitor_flows;
                    }
    
                    return $visitor;
                } else {
                    return null;
                }
            } catch (Exception $e) {
                error_log("Query error: " . $e->getMessage());
                return new WP_Error('db_query_error', esc_html__('There was a problem with the database query.', 'aesirx-analytics'), ['status' => 500]);
            }
        }
    
        function aesirx_analytics_list_consent_common($consents, $visitors, $flows) {
            $list = new \stdClass();
            $list_visitors = [];
            $list_flows = [];
    
            // Assuming $flows is an array of flow data
            foreach ($flows as $flow) {
                $flow = (array) $flow;
                $visitor_uuid = $flow['visitor_uuid'];
                $visitor_vec = isset($list_flows[$visitor_uuid]) ? $list_flows[$visitor_uuid] : [];
                $visitor_vec[] = array(
                    $flow['uuid'],
                    $flow['start'],
                    $flow['end'],
                    $flow['multiple_events']
                );
                $list_flows[$visitor_uuid] = $visitor_vec;
            }
    
            // Assuming $visitors is an array of visitor data
            foreach ($visitors as $visitor) {
                $visitor = (array) $visitor;
                $consent_uuid = $visitor['consent_uuid'];
                $visitor_vec = isset($list_visitors[$consent_uuid]) ? $list_visitors[$consent_uuid] : [];
                $geo_created_at = isset($visitor['geo_created_at']) ? $visitor['geo_created_at'] : null;
                $visitor_vec[] = [
                    'fingerprint' => isset($visitor['fingerprint']) ? $visitor['fingerprint'] : null,
                    'uuid' => $visitor['uuid'],
                    'ip' => $visitor['ip'],
                    'user_agent' => $visitor['user_agent'],
                    'device' => $visitor['device'],
                    'browser_name' => $visitor['browser_name'],
                    'browser_version' => $visitor['browser_version'],
                    'domain' => $visitor['domain'],
                    'lang' => $visitor['lang'],
                    'visitor_flows' => isset($list_flows[$visitor['uuid']]) ? $list_flows[$visitor['uuid']] : null,
                    'geo' => $geo_created_at ? array(
                        $visitor['code'] ?? null,
                        $visitor['name'] ?? null,
                        $visitor['city'] ?? null,
                        $visitor['region'] ?? null,
                        $visitor['isp'] ?? null,
                        $geo_created_at
                    ) : null
                ];
    
                $list_visitors[$consent_uuid] = $visitor_vec;
            }
    
            // Assuming $consents is an array of consent data
            foreach ($consents as $consent) {
                $consent = (array) $consent;
                $uuid_string = $consent['uuid'];
                $outgoing_consent = new \stdClass();
                $outgoing_consent->uuid = $uuid_string;
                $outgoing_consent->wallet_uuid = isset($consent['wallet_uuid']) ? $consent['wallet_uuid'] : null;
                $outgoing_consent->address = $consent['address'] ?? null;
                $outgoing_consent->network = $consent['network'] ?? null;
                $outgoing_consent->web3id = $consent['web3id'] ?? null;
                $outgoing_consent->consent = $consent['consent'];
                $outgoing_consent->datetime = $consent['datetime'];
                $outgoing_consent->expiration = isset($consent['expiration']) ? $consent['expiration'] : null;
                $outgoing_consent->visitor = isset($list_visitors[$uuid_string]) ? $list_visitors[$uuid_string] : [];
    
                $list->consents[] = $outgoing_consent;
            }
    
            if (!empty($list->consents)) {
                $list->consents_by_domain = self::aesirx_analytics_group_consents_by_domains($list->consents);
            }
    
            return $list;
        }

        function aesirx_analytics_get_ip_list_without_geo($params = []) {
            global $wpdb;

            $allowed = [];
            $bind = [];

            $sql       = $wpdb->prepare("SELECT distinct ip FROM {$wpdb->prefix}analytics_visitors WHERE geo_created_at IS NULL");
            $total_sql = $wpdb->prepare("SELECT count(distinct ip) as total FROM {$wpdb->prefix}analytics_visitors WHERE geo_created_at IS NULL");
            
            $list_response = self::aesirx_analytics_get_list($sql, $total_sql, $params, $allowed, $bind);
            
            if (is_wp_error($list_response)) {
                return $list_response;
            }
            
            $list = $list_response['collection'];
    
            $ips = [];
            
            foreach ($list as $one) {
                $ips[] = $one['ip'];
            }
    
            return $ips;
        }

        function aesirx_analytics_update_null_geo_per_ip($ip, $geo) {
            global $wpdb;

            $data = array(
                'isp'           => sanitize_text_field($geo['isp']),
                'country_code'  => sanitize_text_field($geo['country']['code']),
                'country_name'  => sanitize_text_field($geo['country']['name']),
                'city'          => sanitize_text_field($geo['city']),
                'region'        => sanitize_text_field($geo['region']),
                'geo_created_at'=> gmdate('Y-m-d H:i:s', strtotime($geo['created_at'])),
            );
            
            $where = array(
                'geo_created_at' => null,
                'ip'             => sanitize_text_field($ip),
            );
            
            // Execute the update
            $result = $wpdb->update(
                $wpdb->prefix . 'analytics_visitors',
                $data,
                $where,
                array('%s', '%s', '%s', '%s', '%s', '%s'), // Data types for values in $data
                array('%s', '%s') // Data types for values in $where
            );
        }

        function aesirx_analytics_update_geo_per_uuid($uuid, $geo) {
            global $wpdb;

            $data = array(
                'isp'           => sanitize_text_field($geo['isp']),
                'country_code'  => sanitize_text_field($geo['country']['code']),
                'country_name'  => sanitize_text_field($geo['country']['name']),
                'city'          => sanitize_text_field($geo['city']),
                'region'        => sanitize_text_field($geo['region']),
                'geo_created_at'=> gmdate('Y-m-d H:i:s', strtotime($geo['created_at'])),
            );
            
            $where = array(
                'uuid' => sanitize_text_field($uuid),
            );
            
            // Execute the update
            $result = $wpdb->update(
                $wpdb->prefix . 'analytics_visitors',
                $data,
                $where,
                array('%s', '%s', '%s', '%s', '%s', '%s'), // Data types for values in $data
                array('%s') // Data type for the 'uuid' in $where
            );
        }

        function aesirx_analytics_decode_web3id ($token) {
            $api_url = 'http://dev01.aesirx.io:8888/check/web3id';
            $response = wp_remote_get($api_url, array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . sanitize_text_field($token),
                ),
            ));

            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                error_log('API error: ' . $error_message);
                return new WP_Error('validation_error', esc_html__('Something went wrong', 'aesirx-analytics'));
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            return $data;
        }
    }
}