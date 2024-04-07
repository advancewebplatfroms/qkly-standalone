<?php
namespace Qkly;

class Documentation
{
    protected static $schema;
    public function __construct()
    {


    }

    public static function dispatcher()
    {
        $migrations_dir = APP_DIR . 'migrations' . DS;
        $allMigrations = scandir($migrations_dir);
        $allMigrations = array_filter($allMigrations, function ($file) {
            return strpos($file, '.json') !== false;
        });
        natsort($allMigrations);
        self::$schema = [];
        foreach ($allMigrations as $migration) {
            $migrationPath = $migrations_dir . $migration;
            if (file_exists($migrationPath)) {
                $schemaData = json_decode(file_get_contents($migrationPath), true);
                self::$schema = array_merge(self::$schema, $schemaData);
            }
        }
        self::render();
    }

    private static function buildJoinMap()
    {
        $joinMap = [];
        foreach (self::$schema as $tableName => $tableColumns) {
            foreach ($tableColumns as $columnName => $columnDetails) {
                if (isset($columnDetails['relation'])) {
                    list($referencedTable) = explode('(', $columnDetails['relation']);
                    $joinMap[$referencedTable][$tableName] = true; // Add the table as a possible join for the referenced table
                }
            }
        }
        return $joinMap;
    }

    public static function generateSchema()
    {
        $currentUrl = (empty($_SERVER['HTTPS']) ? 'http' : 'https') . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $baseUrl = rtrim(str_replace('/docs/', '/', $currentUrl), '/') . '/api/';
        $openapi = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => "Documentation",
                'version' => '1.0.0',
            ],
            'servers' => [
                [
                    'url' => $baseUrl,
                ],
            ],
            'paths' => [],
            'components' => [
                'schemas' => [],
            ],
        ];

        $joinMap = self::buildJoinMap();

        foreach (self::$schema as $tableName => $tableColumns) {
            // $endpointName = 'records/' . strtolower($tableName);
            $endpointName = strtolower($tableName);
            $title = ucwords(str_replace("_", " ", $tableName));
            $schemaProperties = [];
            foreach ($tableColumns as $columnName => $columnDetails) {
                $schemaProperties[$columnName] = [
                    'type' => self::mapColumnTypeToOpenAPIType($columnDetails['type']),
                    'nullable' => isset($columnDetails['nullable']) ? $columnDetails['nullable'] : false,
                    'default' => isset($columnDetails['default']) ? $columnDetails['default'] : null,
                    'description' => isset($columnDetails['comment']) ? $columnDetails['comment'] : null,
                ];
            }

            // Define the GET operation for retrieving records with joins and filters
            $openapi['paths']['/' . $endpointName] = [
                'get' => [
                    'summary' => 'Retrieve ' . $title,
                    'parameters' => self::buildParameters($tableName, $joinMap),
                    'responses' => [
                        '200' => [
                            'description' => 'Successful response',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'records' => [
                                                'type' => 'array',
                                                'items' => [
                                                    'type' => 'object',
                                                    'properties' => $schemaProperties,
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'post' => [
                    'summary' => 'Create new ' . $title,
                    'requestBody' => [
                        'description' => 'Create new ' . $title,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => $schemaProperties,
                                    'required' => array_keys(array_filter($schemaProperties, function ($prop) {
                                        return !$prop['nullable'];
                                    })),
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '201' => [
                            'description' => 'Successful creation',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => $schemaProperties,
                                    ],
                                ],
                            ],
                        ],
                        '400' => [
                            'description' => 'Invalid input',
                        ],
                    ],
                ],
                'put' => [
                    'summary' => 'Update ' . $title . ' by ID',
                    'requestBody' => [
                        'description' => 'Updated ' . $title,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => $schemaProperties,
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Successful update',
                        ],
                        '404' => [
                            'description' => $title . ' not found',
                        ],
                    ],
                ],
                'delete' => [
                    'summary' => 'Delete ' . $title . ' by ID',
                    'responses' => [
                        '204' => [
                            'description' => 'Successful deletion',
                        ],
                        '404' => [
                            'description' => $title . ' not found',
                        ],
                    ],
                ],
            ];



            // Generate OpenAPI schema components for each table
            $openapi['components']['schemas'][$tableName] = [
                'type' => 'object',
                'properties' => $schemaProperties,
            ];
        }

        

        return json_encode($openapi, JSON_PRETTY_PRINT);
    }

    private static function buildParameters($tableName, $joinMap)
    {
        $parameters = [
            [
                'name' => 'filter',
                'in' => 'query',
                'description' => 'Apply filters to the query. Filter format: column_name,match_type,value (e.g., "name,eq,Internet"). <br>
                <small>
                <ul>
                    <li>"cs": contain string (string contains value)</li>
                    <li>"sw": start with (string starts with value)</li>
                    <li>"ew": end with (string end with value)</li>
                    <li>"eq": equal (string or number matches exactly)</li>
                    <li>"lt": lower than (number is lower than value)</li>
                    <li>"le": lower or equal (number is lower than or equal to value)</li>
                    <li>"ge": greater or equal (number is higher than or equal to value)</li>
                    <li>"gt": greater than (number is higher than value)</li>
                    <li>"bt": between (number is between two comma separated values)</li>
                    <li>"in": in (number or string is in comma separated list of values)</li>
                    <li>"is": is null (field contains "NULL" value)</li>
                </ul>
                </small>
                You can pass multiple filter parameters by providing an array of filter values.<br><br>',
                'schema' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
                'style' => 'form',
                'explode' => true,
            ],
        ];

        if (isset($joinMap[$tableName])) {
            $parameters[] = [
                'name' => 'join',
                'in' => 'query',
                'description' => 'Join operations with related tables.',
                'schema' => [
                    'type' => 'string',
                    'enum' => array_keys($joinMap[$tableName]),
                ],
            ];
        }

        return $parameters;
    }

    private static function mapColumnTypeToOpenAPIType($columnType)
    {
        // Add mappings for your specific column types if needed
        if (strpos($columnType, 'INT') !== false) {
            return 'integer';
        } elseif (strpos($columnType, 'VARCHAR') !== false || strpos($columnType, 'TEXT') !== false) {
            return 'string';
        } elseif (strpos($columnType, 'TIMESTAMP') !== false) {
            return 'string'; // You may want to use 'string' for timestamps, adjust as needed
        } else {
            return 'string'; // Default to 'string' for unknown types
        }
    }
    public static function render()
    {
        $json = self::generateSchema();
        echo '<!doctype html>
        <html lang="en">
          <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
            <title>Api Documentation</title>
            <!-- Embed elements Elements via Web Component -->
            <script src="https://unpkg.com/@stoplight/elements/web-components.min.js"></script>
            <link rel="stylesheet" href="https://unpkg.com/@stoplight/elements/styles.min.css">
            <style>
                body {
                    display: flex;
                    flex-direction: column;
                    height: 100vh;
                }

                main {
                    flex: 1 0 0;
                    overflow: hidden;
                }
            </style>
          </head>
          <body>
            <main role="main">
                <elements-api id="docs" router="hash" layout="sidebar" showPoweredByLink="false"></elements-api>
            </main>
            <script>
            (async () => {
              const docs = document.getElementById("docs");
              const apiDescriptionDocument = ' . $json . ';
            
              docs.apiDescriptionDocument = apiDescriptionDocument;
            })();
            </script>
          </body>
        </html>';
    }

    public static function renderSwagger()
    {
        $json = self::generateSchema();
        echo '<!doctype html>
        <html lang="en">
          <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
            <title>Api Documentation</title>
            <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.9.0/swagger-ui-bundle.min.js"></script>
            <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.9.0/swagger-ui-standalone-preset.js" crossorigin></script>
            <link href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.9.0/swagger-ui.min.css" rel="stylesheet">
          </head>
          <body>
            <div id="swagger-ui"></div>
            <script>
                const apiDescriptionDocument = ' . $json . ';
                window.onload = () => {
                    window.ui = SwaggerUIBundle({
                        spec: apiDescriptionDocument,
                        dom_id: "#swagger-ui",
                        layout: "BaseLayout",
                    });
                };
            </script>
          </body>
        </html>';
    }

}