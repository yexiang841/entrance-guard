#!/usr/bin/expect -f
set time 30
spawn ssh root@39.108.213.67
expect {
"*yes/no" { send "yes\r"; exp_continue }
"*password:" { send "Www17tao@123\r" }
}
interact
