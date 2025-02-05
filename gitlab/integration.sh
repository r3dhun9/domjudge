#!/bin/bash

set -euxo pipefail
export PS4='(${BASH_SOURCE}:${LINENO}): - [$?] $ '

DIR=$(pwd)
GITSHA=$(git rev-parse HEAD || true)

# Set up
"$( dirname "${BASH_SOURCE[0]}" )"/base.sh

function log_on_err() {
	echo -e "\\n\\n=======================================================\\n"
	echo "Symfony log:"
	if sudo test -f /opt/domjudge/domserver/webapp/var/log/prod.log; then
		sudo cat /opt/domjudge/domserver/webapp/var/log/prod.log
	fi
}

trap log_on_err ERR

cd /opt/domjudge/domserver

ADMINPASS=$(cat etc/initial_admin_password.secret)

# configure and restart php-fpm
sudo cp /opt/domjudge/domserver/etc/domjudge-fpm.conf "/etc/php/7.2/fpm/pool.d/domjudge-fpm.conf"
sudo /usr/sbin/php-fpm7.2

# configure judgehost
cd /opt/domjudge/judgehost/
sudo cp /opt/domjudge/judgehost/etc/sudoers-domjudge /etc/sudoers.d/
sudo chmod 400 /etc/sudoers.d/sudoers-domjudge
sudo bin/create_cgroups

if [ ! -d ${DIR}/chroot/domjudge/ ]; then
	cd ${DIR}/misc-tools
	time sudo ./dj_make_chroot -a amd64
fi

# download domjudge-scripts for API check
cd $HOME
composer -n require justinrainbow/json-schema
PATH=${PATH}:${HOME}/vendor/bin
git clone --depth=1 https://github.com/DOMjudge/domjudge-scripts.git
CHECK_API=${HOME}/domjudge-scripts/contest-api/check-api.sh

# 8hours as a helper so we can adjust contest start/endtime
TIMEHELP=$((8*60*60))
# Database changes to make the REST API and event feed match better.
cat <<EOF | mysql domjudge
DELETE FROM clarification;
UPDATE contest SET starttime = UNIX_TIMESTAMP()-$TIMEHELP WHERE cid = 2;
UPDATE contest SET freezetime = UNIX_TIMESTAMP()+15 WHERE cid = 2;
UPDATE contest SET endtime = UNIX_TIMESTAMP()+$TIMEHELP WHERE cid = 2;
UPDATE team_category SET visible = 1;
EOF

# start judgedaemon
cd /opt/domjudge/judgehost/
bin/judgedaemon -n 0 &
sleep 5

# write out current log to learn why it might be broken
cat /var/log/nginx/domjudge.log

# Print the symfony log if it exists
if sudo test -f /opt/domjudge/domserver/webapp/var/log/prod.log; then
  sudo cat /opt/domjudge/domserver/webapp/var/log/prod.log
fi

# submit test programs
cd ${DIR}/tests
make check test-stress

# Prepare to load example problems from Kattis/problemtools
echo "INSERT INTO userrole (userid, roleid) VALUES (3, 1);" | mysql domjudge
cd /tmp
git clone --depth=1 https://github.com/Kattis/problemtools.git
cd problemtools/examples
mv hello hello_kattis
for i in hello_kattis different guess; do
	(
		cd "$i"
		zip -r "../${i}.zip" -- *
	)
	curl --fail -X POST -n -N -F zip[]=@${i}.zip http://localhost/domjudge/api/contests/2/problems
done

# wait for and check results
NUMSUBS=$(curl --fail http://admin:$ADMINPASS@localhost/domjudge/api/contests/2/submissions | python -mjson.tool | grep -c '"id":')
export COOKIEJAR
COOKIEJAR=$(mktemp --tmpdir)
export CURLOPTS="--fail -sq -m 30 -b $COOKIEJAR"

# Make an initial request which will get us a session id, and grab the csrf token from it
CSRFTOKEN=$(curl $CURLOPTS -c $COOKIEJAR "http://localhost/domjudge/login" 2>/dev/null | sed -n 's/.*_csrf_token.*value="\(.*\)".*/\1/p')
# Make a second request with our session + csrf token to actually log in
curl $CURLOPTS -c $COOKIEJAR -F "_csrf_token=$CSRFTOKEN" -F "_username=admin" -F "_password=$ADMINPASS" "http://localhost/domjudge/login"

# Send a general clarification to later test if we see the event.
curl $CURLOPTS -F "sendto=" -F "problem=2-" -F "bodytext=Testing" -F "submit=Send" \
	 "http://localhost/domjudge/jury/clarifications/send" -o /dev/null

while /bin/true; do
	curl $CURLOPTS "http://localhost/domjudge/jury/judging-verifier?verify_multiple=1" -o /dev/null
	NUMNOTVERIFIED=$(curl $CURLOPTS "http://localhost/domjudge/jury/judging-verifier" | grep "submissions checked" | sed -r 's/^.* ([0-9]+) submissions checked.*$/\1/')
	NUMVERIFIED=$(curl $CURLOPTS "http://localhost/domjudge/jury/judging-verifier" | grep "submissions not checked" | sed -r 's/^.* ([0-9]+) submissions not checked.*$/\1/')
	if [ $NUMSUBS -gt $((NUMVERIFIED+NUMNOTVERIFIED)) ]; then
		sleep 30s
	else
		break
	fi
done

NUMNOMAGIC=$(curl $CURLOPTS "http://localhost/domjudge/jury/judging-verifier" | grep "without magic string" | sed -r 's/^.* ([0-9]+) without magic string.*$/\1/')

# include debug output here
if [ $NUMNOTVERIFIED -ne 2 ] || [ $NUMNOMAGIC -ne 0 ]; then
	echo "Exactly 2 submissions are expected to be unverified, but $NUMNOTVERIFIED are."
	echo "Of these $NUMNOMAGIC do not have the EXPECTED_RESULTS string (should be 0)."
	curl $CURLOPTS "http://localhost/domjudge/jury/judging-verifier?verify_multiple=1"
	for i in /opt/domjudge/judgehost/judgings/*/*/*/compile.out; do
		echo $i;
		head -n 100 $i;
		dir=$(dirname $i)
		if [ -r $dir/testcase001/system.out ]; then
			head $dir/testcase001/system.out
			head $dir/testcase001/runguard.err
			head $dir/testcase001/program.err
			head $dir/testcase001/program.meta
		fi
		echo;
	done
	cat /proc/cmdline
	cat /chroot/domjudge/etc/apt/sources.list
	exit -1;
fi

# Check the Contest API:
$CHECK_API -n -C -a 'strict=1' http://admin:$ADMINPASS@localhost/domjudge/api

# Validate the eventfeed against the api(currently ignore failures)
cd ${DIR}/misc-tools
./compare-cds.sh http://localhost/domjudge 2 || true
