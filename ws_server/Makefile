CXX      = g++
CXXFLAGS = -std=c++17 -O2 -Wall -Wextra
LDFLAGS  = -lmysqlclient -lpthread -lssl -lcrypto

TARGET   = ws_balance

all: $(TARGET)

$(TARGET): ws_balance.cpp
	$(CXX) $(CXXFLAGS) -o $@ $< $(LDFLAGS)

clean:
	rm -f $(TARGET)

.PHONY: all clean
