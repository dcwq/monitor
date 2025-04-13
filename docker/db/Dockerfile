FROM mysql:5.7

ADD slow_log_command.sql /
ADD command.sh /

RUN apt-get update \
 && apt-get install -y nano \
 && chmod 777 command.sh \
 && chmod 777 slow_log_command.sql;
