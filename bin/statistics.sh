cat /opt/entrance-guard/log/ws-$(date -d last-day +%Y-%m-%d).log | grep "WARN\|connection" > /opt/entrance-guard/log/cc-$(date -d last-day +%Y-%m-%d).log.`cat /opt/entrance-guard/log/ws-$(date -d last-day +%Y-%m-%d).log | grep close | wc -l`
