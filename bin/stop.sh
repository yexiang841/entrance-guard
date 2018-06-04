#!/bin/bash

ps aux | grep WorkerMan | grep -v grep | awk '{print $2}' | xargs kill -9

