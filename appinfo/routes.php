<?php

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'scan#scan', 'url' => '/scan', 'verb' => 'POST'],
		['name' => 'group#index', 'url' => '/groups', 'verb' => 'GET'],
		['name' => 'group#delete', 'url' => '/groups/{id}/delete', 'verb' => 'POST'],
		['name' => 'group#dismiss', 'url' => '/groups/{id}/dismiss', 'verb' => 'POST'],
		['name' => 'config#get', 'url' => '/scan-config', 'verb' => 'GET'],
		['name' => 'config#save', 'url' => '/scan-config', 'verb' => 'POST'],
		['name' => 'ignore#adminList', 'url' => '/ignore/admin', 'verb' => 'GET'],
		['name' => 'ignore#adminAdd', 'url' => '/ignore/admin', 'verb' => 'POST'],
		['name' => 'ignore#adminRemove', 'url' => '/ignore/admin', 'verb' => 'DELETE'],
		['name' => 'ignore#userList', 'url' => '/ignore/user', 'verb' => 'GET'],
		['name' => 'ignore#userAdd', 'url' => '/ignore/user', 'verb' => 'POST'],
		['name' => 'ignore#userRemove', 'url' => '/ignore/user', 'verb' => 'DELETE'],
	],
];
