<?php

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'scan#stats', 'url' => '/scan/stats', 'verb' => 'GET'],
		['name' => 'scan#start', 'url' => '/scan/start', 'verb' => 'POST'],
		['name' => 'scan#chunk', 'url' => '/scan/chunk', 'verb' => 'POST'],
		['name' => 'scan#finish', 'url' => '/scan/finish', 'verb' => 'POST'],
		['name' => 'group#index', 'url' => '/groups', 'verb' => 'GET'],
		['name' => 'group#delete', 'url' => '/groups/{id}/delete', 'verb' => 'POST'],
		['name' => 'group#hide', 'url' => '/groups/{id}/hide', 'verb' => 'POST'],
		['name' => 'group#unhide', 'url' => '/groups/{id}/unhide', 'verb' => 'POST'],
		['name' => 'config#get', 'url' => '/scan-config', 'verb' => 'GET'],
		['name' => 'config#save', 'url' => '/scan-config', 'verb' => 'POST'],
		['name' => 'userConfig#get', 'url' => '/user-config', 'verb' => 'GET'],
		['name' => 'userConfig#save', 'url' => '/user-config', 'verb' => 'POST'],
		['name' => 'ignore#adminList', 'url' => '/ignore/admin', 'verb' => 'GET'],
		['name' => 'ignore#adminAdd', 'url' => '/ignore/admin', 'verb' => 'POST'],
		['name' => 'ignore#adminRemove', 'url' => '/ignore/admin', 'verb' => 'DELETE'],
		['name' => 'ignore#userList', 'url' => '/ignore/user', 'verb' => 'GET'],
		['name' => 'ignore#userAdd', 'url' => '/ignore/user', 'verb' => 'POST'],
		['name' => 'ignore#userRemove', 'url' => '/ignore/user', 'verb' => 'DELETE'],
		['name' => 'group#recheck', 'url' => '/groups/{id}/recheck', 'verb' => 'POST'],
		['name' => 'cache#clear', 'url' => '/cache/clear', 'verb' => 'POST'],
		['name' => 'cache#stats', 'url' => '/cache/stats', 'verb' => 'GET'],
	],
];
