#!/usr/bin/expect -f
set time 30
spawn ssh root@120.79.69.80
expect {
"*yes/no" { send "yes\r"; exp_continue }
"*password:" { send "Www17tao@123\r" }
}
interact
