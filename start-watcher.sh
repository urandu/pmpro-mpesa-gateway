#!/bin/bash

watchman watch . && watchman -- trigger . auto-commit '*' -- ./auto-commit.sh