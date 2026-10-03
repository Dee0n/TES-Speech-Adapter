set -e
SRC=/home/dwemer/TES-Speech-Adapter/ext/tes_agent
HER=/var/www/html/HerikaServer
for f in $SRC/*.php; do php -l "$f"; done
ls -l /usr/bin/php
cp -r "$SRC" "$HER/ext/"
chown -R dwemer:www-data "$HER/ext/tes_agent"
# spawn test exactly like Apache would, but dry (nothing reaches the game outbox)
cd $HER
runuser -u www-data -- php -r '
$enginePath="/var/www/html/HerikaServer/";
require_once $enginePath."lib/runtime_bootstrap.php";
chimRuntimeBootstrap($enginePath, ["load_general_settings"=>true]);
require_once $enginePath."ext/tes_agent/lib.php";
var_dump(tesAgentStart("подготовь Лидию к бою с драконом", true));
'
sleep 5
ps aux | grep -c "[w]orker.php --task"
